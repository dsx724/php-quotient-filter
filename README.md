[blog](http://www.xuetech.com/search/label/Bloom%20Filter)
======

php-quotient-filter
================
* This is a single threaded quotient filter implementation in pure PHP.
* It uses a binary string to store the bit vector and manipulates based on byte indexes of the string.
* [Apache 2.0 License](https://raw.github.com/dsx724/php-quotient-filter/master/LICENSE).

This implementation supports 33 bit quotients (2^33 slots) and remainders up to 63 bits at the expensive of some performance.


cautionary tales
================
* PHP Limitations
	* Strings are limited to byte addressing of signed 32 bit integers.  The maximum string is only 2GB - 1B (2^31-1 Bytes).
	* The bit vector only supports powers of 2 bits in this implementation.  Thus the largest vector size is 1GB.
	* Workaround with multiple strings could allow for implementations greater than 1GB.
	* PHP 5.4+
	* PHP lacks calloc or malloc so str_repeat is used to allocate the bit array.
	* PHP cannot directly use the output of str_repeat and primitive assignment will require double the memory of the vector size due to the copy.