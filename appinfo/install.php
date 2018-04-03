<?php
//make staticperl executable for people without perl.
$perl_bin = __DIR__ . '/../bin/staticperl';
if (file_exists($perl_bin) && is_writable($perl_bin)) {
	chmod($perl_bin, 0744);
}