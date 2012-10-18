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
	public static function createFromProbability($n, $prob, $extra_bits = 0){
		if ($prob <= 0 || $prob >= 1) throw new Exception('Invalid false positive rate requested.');
		if ($n <= 0) throw new Exception('Invalid capacity requested.');
		//TODO create Quotient Filter by calculating p and q from n and prob
		
		return new self($p,$q);
	}
	// run - remainders with the same quotient stored continuously
	// cluster - a maximal sequence of slots whose first element is in the canonical slot - contain 1 or more run
	// is_occupied - canonical slot
	// is_continuation -  part of run
	// is_shifted - remainder not in canonical slot
	
	private $n = 0; // elements
	private $q; // # of bits in slot addressing
	private $r; // # of bits to store in slot (add 3 bits to get slot size)
	private $slots; // 2 ^ q
	private $slot_size; // r + 3
	private $m; // memory size of bit array in bits (slots * slot_size)
	private $hash;
	private $chunk_size;
	private $bit_array;
	
	public function __construct($p, $q, $h='md5'){
		$this->slots = 1 << ($this->q = $q);
		$this->slot_size = ($this->r = $p - $q) + 3;
		$this->m = $this->slots * $this->slot_size;
		if ($this->slot_size > 63) throw new Exception('This implementation of the quotient filter only supports 63 bit slots.');
		if ($p - $q <= 0) throw new Exception('The fingerprint is too small to support the number of slots.');
		if ($q < 1) throw new Exception('There must be at least one slot.');
		if ($this->m > 17179869183) throw new Exception('The maximum data structure size is 1GB.');
		$this->hash = $h;
		$this->chunk_size = ceil($p / 8);
		$this->bit_array = (binary)(str_repeat("\0",$this->m >> 3));
	}
	public function calculateProbability($n = 0){
		if ($n > $this->slots) throw new Exception('The data structure is not large enough to support the number of elements.');
		return 1 - exp(-($n ?: $this->n)/($this->slots * (1 << $this->r)));
	}
	public function calculateCapacity($p){
		return min($this->slots,floor(-log(1 - $p) * $this->slots * (1 << $this->r)));
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
	private function getSlot($i){
		//var_dump('i',$i);
		$start = $this->slot_size * $i;
		$start_word = $start >> 3;
		$start_bit = $start % 8;
		//var_dump('Start',$start);
		//var_dump($start_word,$start_bit);
		$end = $this->slot_size * ($i + 1) - 1;
		$end_word = $end >> 3;
		$end_bit = $end % 8;
		//var_dump('End',$end);
		//var_dump($end_word,$end_bit);
		$slice = substr($this->bit_array,$start_word,$end_word - $start_word + 1);
		//var_dump('Slice',$slice,base_convert(unpack('H*', $slice)[1], 16, 2));
		$mask = (1 << (8 - $start_bit)) - 1;
		$slot = ord($slice[0]) & $mask;
		//var_dump('Piece',$slot);
		for ($i = 1; $i < strlen($slice); $i++){
			$slot <<= 8;
			$slot |= ord($slice[$i]);
			//var_dump($slot);
		}
		$slot >>= 7 - $end_bit;
		//var_dump($slot);
		return $slot;
	}
	private function setSlot($i,$s){
		$start = $this->slot_size * $i;
		$start_word = $start >> 3;
		$start_bit = $start % 8;
		//var_dump('Start',$start);
		//var_dump($start_word,$start_bit);
		$end = $this->slot_size * ($i + 1) - 1;
		$end_word = $end >> 3;
		$end_bit = $end % 8;
		//var_dump('End',$end);
		//var_dump($end_word,$end_bit);
		if ($start_word === $end_word){
			// double ended mask
			$mask = ((2 << ($end_bit - $start_bit)) - 1) << (7 - $end_bit);
			//var_dump('Mask',$this->printByte($mask));
			$current = ord($this->bit_array[$start_word]);
			//var_dump('Current',$this->printByte($current));
			//r = a ^ ((a ^ b) & mask);
			//var_dump('Replace',$this->printByte($s));
			$replacement = $s << (7 - $end_bit);
			//var_dump('SReplace',$this->printByte($current),$this->printByte($replacement),'XOR',$this->printByte($current ^ $replacement),$this->printByte((($current ^ $replacement) & $mask)));
			$current = $current ^ (($current ^ $replacement) & $mask);
			//var_dump('Cleared',$this->printByte($current));
			$this->bit_array[$start_word] = chr($current);
		} else {
			// single ended mask
			// last
			$mask = ((2 << ($end_bit)) - 1) << (7 - $end_bit);
			var_dump('Mask',$this->printByte($mask));
			$current = ord($this->bit_array[$end_word]);
			var_dump('Current',$this->printByte($current));
			$replacement = ($s & ((2 << $end_bit) - 1)) << (7 - $end_bit);
			var_dump('Replacement',$this->printByte($replacement));
			$current = $current ^ (($current ^ $replacement) & $mask);
			$this->bit_array[$end_word] = chr($current);
			$s >>= $end_bit + 1;
			// middle
			for ($j = $end_word - 1; $j > $start_word; $j--){
				$this->bit_array[$j] = chr($s & ((1 << 8) - 1));
				$s >>= 8;
			}
			//first
			$mask = (1 << (8 - $start_bit)) - 1;
			var_dump('Mask',$this->printByte($mask));
			$current = ord($this->bit_array[$start_word]);
			var_dump('Current',$this->printByte($current));
			$replacement = $s;
			$current = $current ^ (($current ^ $replacement) & $mask);
			$this->bit_array[$start_word] = chr($current);
			
			
		}
	}
	public function test(){
		/*
		for ($i = 0; $i < 100; $i++){
			$this->bit_array[$i] = chr($i+1);
			if ($i < 10) var_dump(decbin(ord($this->bit_array[$i])));
		}
		for ($i = 0; $i < 5; $i++){
			echo '<b> Slot '.$i.'</b>'.PHP_EOL;
			var_dump($this->getSlot($i),decbin($this->getSlot($i)),$this->printSlot(decbin($this->getSlot($i))));
		}
		*/
		$this->setSlot(0,5);
		$this->setSlot(5,5);
		$this->setSlot(10,3);
		echo 'TEST'.PHP_EOL;
		var_dump($this->printSlot(decbin($this->getSlot(0))));
		var_dump($this->printSlot(decbin($this->getSlot(5))));
		var_dump($this->printSlot(decbin($this->getSlot(10))));
		var_dump($this->printSlot(decbin($this->getSlot(1))));
		var_dump($this->printSlot(decbin($this->getSlot(2))));
		var_dump($this->printSlot(decbin($this->getSlot(3))));
		var_dump($this->printSlot(decbin($this->getSlot(4))));
		var_dump($this->printSlot(decbin($this->getSlot(6))));
		var_dump($this->printSlot(decbin($this->getSlot(7))));
		var_dump($this->printSlot(decbin($this->getSlot(8))));
		var_dump($this->printSlot(decbin($this->getSlot(9))));
	}
	public function grow($bits = 1){
		
	}
	public function shrink($bits = 1){
		
	}
	public function printSlot($s){
		return trim(strrev(
			implode(' ',array_map(
				function ($line){ return str_pad($line,$this->slot_size % 8 ?: 8,'0',STR_PAD_RIGHT); },
				explode("\r\n",trim(chunk_split(strrev(str_pad($s,$this->slot_size,'0',STR_PAD_LEFT)),8)))
			)))
		);
	}
	public function printByte($i){
		return str_pad(is_string($i) ? decbin(ord($i)) : decbin($i),8,'0',STR_PAD_LEFT);
	}
}
?>