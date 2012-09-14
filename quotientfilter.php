<?php
/* 
Copyright (c) 2012, Da Xue
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:
1. Redistributions of source code must retain the above copyright
   notice, this list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright
   notice, this list of conditions and the following disclaimer in the
   documentation and/or other materials provided with the distribution.
3. All advertising materials mentioning features or use of this software
   must display the following acknowledgement:
   This product includes software developed by Da Xue.
4. The name of the author nor the names of its contributors may be used 
   to endorse or promote products derived from this software without 
   specific prior written permission.

THIS SOFTWARE IS PROVIDED BY DA XUE ''AS IS'' AND ANY
EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL DA XUE BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

/* https://github.com/dsx724/php-quotient-filter */

// p bit fingerprint of elements
// q msbs - stores remainders in sorted order
// r lsbs - stored in the bucket indexed by q (quotient)
// run - remainders with the same quotient stored continuously
// cluster - a maximal sequence of slots whose first element is in the canonical slot - contain 1 or more run
// is_occupied - canonical slot
// is_continuation -  part of run
// is_shifted - remainder not in canonical slot

// a = n/m - load factor
// m = 2^q - number of slots
// 1 - e^(-a/2^r) <= 2^-r

interface iAMQ {
	public function add($key);
	public function contains($key);
}

class QuotientFilter implements iAMQ {
	public static function createFromProbability($n, $prob, $expansion_bits){
		if ($prob <= 0 || $prob >= 1) throw new Exception('Invalid false positive rate requested.');
		if ($n <= 0) throw new Exception('Invalid capacity requested.');
		
		return new self($p,$q);
	}
	/*
	public static function getUnion($bf1,$bf2){
		if ($bf1->m != $bf2->m) throw new Exception('Unable to merge due to vector difference.');
		if ($bf1->k != $bf2->k) throw new Exception('Unable to merge due to hash count difference.');
		if ($bf1->hash != $bf2->hash) throw new Exception('Unable to merge due to hash difference.');
		$bf = new BloomFilter($bf1->m,$bf1->k,$bf1->hash);
		$bf->n = $bf1->n + $bf2->n;
		for ($i = 0; $i < strlen($bf->bit_array); $i++) $bf->bit_array[$i] = chr(ord($bf1->bit_array[$i]) | ord($bf2->bit_array[$i]));
		return $bf;
	}
	public static function getIntersection($bf1,$bf2){
		if ($bf1->m != $bf2->m) throw new Exception('Unable to merge due to vector difference.');
		if ($bf1->k != $bf2->k) throw new Exception('Unable to merge due to hash count difference.');
		if ($bf1->hash != $bf2->hash) throw new Exception('Unable to merge due to hash difference.');
		$bf = new BloomFilter($bf1->m,$bf1->k,$bf1->hash);
		$bf->n = abs($bf1->n - $bf2->n);
		for ($i = 0; $i < strlen($bf->bit_array); $i++) $bf->bit_array[$i] = chr(ord($bf1->bit_array[$i]) & ord($bf2->bit_array[$i]));
		return $bf;
	}
	*/
	// run - remainders with the same quotient stored continuously
	// cluster - a maximal sequence of slots whose first element is in the canonical slot - contain 1 or more run
	// is_occupied - canonical slot
	// is_continuation -  part of run
	// is_shifted - remainder not in canonical slot
	
	private $n = 0;
	private $q; // # of bits in slot addressing
	private $r; // # of bits to store in slot (add 3 bits to get slot size)
	private $slots; // 2 ^ q
	private $slot_size; // 2 ^ (r + 3)
	private $m;
	private $hash;
	private $chunk_size;
	private $bit_array;
	
	public function __construct($p, $q, $h='md5'){
		$this->slots = 2 << ($this->q = $q);
		$this->slot_size = ($this->r = $p - $q) + 3;
		$this->m = $this->slots * $this->slot_size;
		if ($p - $q <= 0) throw new Exception('The fingerprint is too small to support the number of slots.');
		if ($q < 3) throw new Exception('The number of slots must be at least 8.');
		if ($this->m > 17179869183) throw new Exception('The maximum data structure size is 1GB.');
		$this->hash = $h;
		$this->chunk_size = ceil($p / 8);
		$this->bit_array = (binary)(str_repeat("\0",$this->m >> 3));
	}
	public function calculateProbability($n = 0){
		return 1 - exp(-($n ?: $this->n)/($this->slots * (2 << $this->r)));
	}
	public function calculateCapacity($p){
		return floor(-log(1 - $p) * $this->slots * (2 << $this->r));
	}
	public function getElementCount(){
		return $this->n;
	}
	public function getSlotCount(){
		return $this->slots;
	}
	public function getSlotSize(){
		return $this->slot_size;
	}
	public function getArraySize($bytes = false){
		return $this->m >> ($bytes ? 3 : 0);
	}
	public function getLoadFactor(){
		return $this->n / $this->slots;
	}
	public function getInfo($p = null){
		$units = array('','K','M','G','T','P','E','Z','Y');
		$M = $this->getArraySize(true);
		$magnitude = floor(log($M,1024));
		$unit = $units[$magnitude];
		$M /= pow(1024,$magnitude);
		return 'Allocated '.$this->getArraySize().' bits ('.$M.' '.$unit.'Bytes)'.PHP_EOL.
			'Allocated '.$this->getSlotCount(). ' slots of '.$this->getSlotSize().' bits'.PHP_EOL.
			'Contains '.$this->getElementCount().' elements (a='.$this->getLoadFactor().')'.PHP_EOL.
			(isset($p) ? 'Capacity of '.number_format($this->calculateCapacity($p)).' (p='.$p.')'.PHP_EOL : '');
	}
	public function add($key){
		$hash = hash($this->hash,$key,true);
		while ($this->chunk_size > strlen($hash)) $hash .= hash($this->hash,$hash,true);
		
		$this->n++;
	}
	public function contains($key){
		$hash = hash($this->hash,$key,true);
		while ($this->chunk_size > strlen($hash)) $hash .= hash($this->hash,$hash,true);
		
		return true;
	}
	
	/*
	public function unionWith($bf){
		if ($this->m != $bf->m) throw new Exception('Unable to merge due to vector difference.');
		if ($this->k != $bf->k) throw new Exception('Unable to merge due to hash count difference.');
		if ($this->hash != $bf->hash) throw new Exception('Unable to merge due to hash difference.');
		
	}
	public function intersectWith($bf){
		if ($this->m != $bf->m) throw new Exception('Unable to merge due to vector difference.');
		if ($this->k != $bf->k) throw new Exception('Unable to merge due to hash count difference.');
		if ($this->hash != $bf->hash) throw new Exception('Unable to merge due to hash difference.');
		
	}
	*/
	public function grow(){
		
	}
	public function shrink(){
		
	}
}
?>