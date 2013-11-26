###Summary
THIS PROJECT IS NOT COMPLETE!  I've been busy with other things and only provide basic (add, contains) functionality.

This is a proper quotient implementation in PHP.  I could not find any open-source implementation of it.
Like the bloom filter, a quotient filter is a probabilistic data structure that trades of 
It uses a binary string to store the bit vector and manipulates based on byte indexes of the string.

###NOT COMPLETE - IGNORE BELOW THIS LINE!

###Performance

###Math Bits

###Notes and Limitations

Fingerprints and Remainders are held separately so that underlying storage and retrieval can be implemented separately.

* PHP 5.x variables are limited to byte addressing of signed 32 bit integers.  
	* The the maximum variable is only 2GB - 1B (2^31-1 Bytes).
	* Minor edits are required to support PHP 5.3 due to the use of array dereferencing features of PHP 5.4.
	* PHP cannot directly use the output of str_repeat and primitive assignment will require double the memory of the vector size.
* PHP lacks calloc or malloc so str_repeat is used to allocate the bit array.