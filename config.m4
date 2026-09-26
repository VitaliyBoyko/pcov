dnl $Id$
dnl config.m4 for extension pcov

PHP_ARG_ENABLE(pcov, whether to enable php coverage support,
[  --enable-pcov           Enable php coverage support])

if test "$PHP_PCOV" != "no"; then
  PHP_VERSION=$($PHP_CONFIG --vernum)

  AC_CHECK_FUNCS([clock_gettime getrusage])
  if test "$ac_cv_func_clock_gettime" = "yes" -a \
          "$ac_cv_func_getrusage" = "yes"; then
    AC_DEFINE([HAVE_PCOV_NATIVE_EXPORT], [1],
      [Define to 1 when synchronous native export is supported])
  fi

  AC_MSG_CHECKING(PHP version)

  if test $PHP_VERSION -lt 80300 -o $PHP_VERSION -ge 80600; then
    AC_MSG_ERROR([this PCOV release supports PHP 8.3, 8.4, and 8.5])
  fi

  AC_MSG_RESULT($PHP_VERSION)
  PHP_ADD_EXTENSION_DEP(pcov, hash)
  PHP_NEW_EXTENSION(pcov, pcov.c pcov_dump.c pcov_fingerprint.c, $ext_shared,, -DZEND_ENABLE_STATIC_TSRMLS_CACHE=1 -std=c99)
fi
