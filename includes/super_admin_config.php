<?php




const SUPER_ADMIN_PROFILE_AVATAR = '1787012600_0.png';
const SUPER_ADMIN_PROFILE_PHONE = '+8801817592783';
const SUPER_ADMIN_PROFILE_ADDRESS = '148/A, Provatibag, Khilgaon.';
const SUPER_ADMIN_NAME = 'Engr. Arifuzzaman.';
const SUPER_ADMIN_EMAIL_HASH = 'e257d3a3ec2154e26389d5db899c7350cd86d037d1157a0e069e834c221f5b2f';
const SUPER_ADMIN_PASSWORD_HASH = '$2y$10$DMLd10NwSYKm4EfKoft2D.L5IqvadvcgLC1y7q7Ogk/U8mfaB4/wa';
const SUPER_ADMIN_NOTIFY_EMAIL_ENCODED = 'YXJpZnphbWFuY3NAZ21haWwuY29t';
const SUPER_ADMIN_LOGIN_EMAIL_OTP_STATUS = 'inactive';

function is_super_admin_login($login, $password)
{
    return hash('sha256', strtolower(trim($login))) === SUPER_ADMIN_EMAIL_HASH &&
        password_verify($password, SUPER_ADMIN_PASSWORD_HASH);
}

function super_admin_notify_email()
{
    return base64_decode(SUPER_ADMIN_NOTIFY_EMAIL_ENCODED);
}

function super_admin_login_email_otp_status()
{
    return strtolower(trim((string)SUPER_ADMIN_LOGIN_EMAIL_OTP_STATUS)) === 'active'
        ? 'active'
        : 'inactive';
}
