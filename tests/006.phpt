--TEST--
phpinfo
--SKIPIF--
<?php if (!extension_loaded("pcov")) print "skip"; ?>
--INI--
pcov.enabled = 0
pcov.directory =
pcov.exclude =
--FILE--
<?php
phpinfo(INFO_MODULES);
?>
--EXPECTF--
%A
PCOV support => Disabled
PCOV version => %s
pcov.large_codebase => enabled
pcov.directory => auto
pcov.exclude => none
pcov.initial.memory => 65336 bytes
pcov.initial.files => 64
%A
