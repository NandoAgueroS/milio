#!/usr/bin/env bash
# Creates the FTP account from the environment, points vsftpd at the shared
# webroot, then hands over to vsftpd in the foreground.
set -euo pipefail

: "${FTP_USER:?FTP_USER is required}"
: "${FTP_PASSWORD:?FTP_PASSWORD is required}"

FTP_UID="${FTP_UID:-1000}"
# The group the web server runs as. Uploaded files must carry a group the web
# server is a member of, and its own group is the only one guaranteed to exist
# on both sides. www-data is gid 33 in the php:8.3-apache image.
WEB_GID="${WEB_GID:-33}"
PASV_ADDRESS="${PASV_ADDRESS:-127.0.0.1}"
PASV_MIN_PORT="${PASV_MIN_PORT:-21000}"
PASV_MAX_PORT="${PASV_MAX_PORT:-21010}"
FTP_ROOT="${FTP_ROOT:-/var/www/html}"

# Reuse the host uid so the developer owns what they upload, and give uploads
# the web server's group so it can write them without world-writable bits.
if ! getent group "${WEB_GID}" >/dev/null; then
    groupadd -g "${WEB_GID}" webgroup
fi
GROUP_NAME="$(getent group "${WEB_GID}" | cut -d: -f1)"

if ! id -u "${FTP_USER}" >/dev/null 2>&1; then
    # Debian's vsftpd PAM stack includes pam_shells, so the shell must be
    # listed in /etc/shells. /bin/bash is; /usr/sbin/nologin is not.
    useradd --no-create-home --home-dir "${FTP_ROOT}" \
        --uid "${FTP_UID}" --gid "${GROUP_NAME}" \
        --shell /bin/bash "${FTP_USER}"
fi

echo "${FTP_USER}:${FTP_PASSWORD}" | chpasswd

# vsftpd refuses to start unless secure_chroot_dir exists, is root-owned and
# is not writable by anyone else.
mkdir -p /var/run/vsftpd/empty
chown root:root /var/run/vsftpd/empty
chmod 0755 /var/run/vsftpd/empty

printf '%s\n' "${FTP_USER}" > /etc/vsftpd.userlist

mkdir -p "${FTP_ROOT}"
# Best effort: the bind mount may belong to the host user already, which is the
# normal case when FTP_UID matches it.
chown "${FTP_UID}:${WEB_GID}" "${FTP_ROOT}" 2>/dev/null || true
# 2775 = group-writable plus setgid, so directories created below inherit the group
chmod 2775 "${FTP_ROOT}" 2>/dev/null || true

sed -i \
    -e "s|^pasv_address=.*|pasv_address=${PASV_ADDRESS}|" \
    -e "s|^pasv_min_port=.*|pasv_min_port=${PASV_MIN_PORT}|" \
    -e "s|^pasv_max_port=.*|pasv_max_port=${PASV_MAX_PORT}|" \
    -e "s|^local_root=.*|local_root=${FTP_ROOT}|" \
    /etc/vsftpd.conf

echo "ftp: starting vsftpd as user '${FTP_USER}' (uid ${FTP_UID}/${GROUP_NAME}) root=${FTP_ROOT} pasv=${PASV_ADDRESS}:${PASV_MIN_PORT}-${PASV_MAX_PORT}"

exec /usr/sbin/vsftpd /etc/vsftpd.conf
