dnl PHP 7.4 ext/odbc/config.m4 включает первый драйвер (Adabas) при сборке
dnl через phpize и падает, не дойдя до unixODBC. Здесь остаётся только unixODBC.

PHP_ARG_WITH([unixODBC],,
  [AS_HELP_STRING([--with-unixODBC], [Include unixODBC support])],
  [yes])

if test "$PHP_UNIXODBC" != "no"; then
  if test "$PHP_UNIXODBC" = "yes"; then
    PKG_CHECK_MODULES([ODBC], [odbc])
    PHP_EVAL_INCLINE([$ODBC_CFLAGS])
  else
    ODBC_INCDIR=$PHP_UNIXODBC/include
    ODBC_LIBDIR=$PHP_UNIXODBC/$PHP_LIBDIR
    ODBC_LFLAGS=-L$ODBC_LIBDIR
    ODBC_CFLAGS=-I$ODBC_INCDIR
    ODBC_LIBS=-lodbc
    PHP_EVAL_INCLINE([$ODBC_CFLAGS])
  fi

  AC_DEFINE(HAVE_UNIXODBC, 1, [ ])
  AC_DEFINE(HAVE_SQLDATASOURCES, 1, [ ])
  AC_DEFINE(HAVE_UODBC, 1, [ ])

  PHP_EVAL_LIBLINE([$ODBC_LFLAGS $ODBC_LIBS], [ODBC_SHARED_LIBADD])
  PHP_SUBST([ODBC_SHARED_LIBADD])
  PHP_NEW_EXTENSION(odbc, php_odbc.c, $ext_shared,, [-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1])
fi
