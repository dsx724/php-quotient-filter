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
// is_continuation - part of run
// is_shifted - remainder not in canonical slot

// a = n/m - load factor
// m = 2^q - number of slots
// 1 - e^(-a/2^r) <= 2^-r

interface iAMQ {
	public function add($key);
	public function contains($key);
}

class QuotientFilter implements iAMQ {
	private $n = 0;
	private $q;
	private $q_mask;
	private $slots;
	private $r;
	private $occupied;
	private $continuation;
	private $shifted;
	private $remainder;
	public static function createFromProbability($n, $p){
		if ($p <= 0 || $p >= 1) throw new Exception('Invalid false positive rate requested.');
		if ($n <= 0) throw new Exception('Invalid capacity requested.');
		$q = (int)ceil(log($n,2));
		$r = -log($p,2); //approximate estimator method
		return new self($q,$r);
	}
	public function __construct($q, $r){
		if ($q < 3) throw new Exception();
		if ($q > 33) throw new Exception();
		if ($r < 1) throw new Exception();
		if ($r > 63) throw new Exception();
		if ((1 << $q) * $r > 8589934592) throw new Exception();
		$this->q = $q;
		$q_size = 1 << ($q - 3);
		$this->q_mask = (1 << $q) - 1;
		$this->slots = 1 << $q;
		$this->r = $r;
		$this->r_mask = (1 << $r) - 1;
		$this->occupied = (binary)(str_repeat("\0",$q_size));
		$this->continuation = (binary)(str_repeat("\0",$q_size));
		$this->shifted = (binary)(str_repeat("\0",$q_size));
		$this->remainder = (binary)(str_repeat("\0",$q_size * $this->r));
	}
	public function add($key){
		$this->n++;
		$hash = md5($key,true);
		$q = (unpack('i',substr($hash,0,8))[1]);
		$r = (unpack('i',substr($hash,8,8))[1]) & $this->r_mask;
		if (($unoccupied = $this->getBitInv($this->occupied,$q)) & $this->getBitInv($this->shifted,$q)){
			$this->setBit($this->occupied,$q,true);
			$this->setRemainder($q,$r);
			return true;
		}
		$run = 0;
		$c_start = $q;
		while ($this->getBit($this->shifted,$c_start)) $run += $this->getBit($this->occupied,$c_start--);
		if ($this->getBitInv($this->occupied,$c_start)) throw new Exception();
		$r_start = $c_start;
		while ($run) $run -= $this->getBitInv($this->continuation,++$r_start);
		$f_start = $r_start;
		$r2 = false;
		$r2_start = $r_start;
		do {
			$f_start++;
			if (!$r2 && $this->getBitInv($this->continuation,$f_start) && ($this->getBit($this->occupied,$f_start) | $this->getBit($this->shifted,$f_start))){
				$r2 = true;
				$r2_start = $f_start;
			}
		} while ($this->getBit($this->occupied,$f_start) | $this->getBit($this->shifted,$f_start));
		if ($r2){
			for ($i = $f_start; $i > $r2_start; $i--){
				$this->setBit($this->continuation,$i,$this->getBit($this->continuation,$i - 1));
				$this->setBit($this->shifted,$i,1);
				$this->setRemainder($i,$this->getRemainder($i - 1));
			}
			$f_start = $i;
		}
		$this->setBit($this->occupied,$q,1);
		$this->setBit($this->continuation,$f_start,!$unoccupied);
		$this->setBit($this->shifted,$f_start,1);
		$r = (unpack('i',substr($hash,8,8))[1]) & $this->r_mask;
		$this->setRemainder($f_start,$r);
	}
	public function contains($key){
		$hash = md5($key,true);
		$q = (unpack('i',substr($hash,0,8))[1]) & $this->q_mask;
		if ($this->getBitInv($this->occupied,$q)) return false;
		$run = 0;
		$c_start = $q;
		while ($this->getBit($this->shifted,$c_start)) $run += $this->getBit($this->occupied,$c_start--);
		if ($this->getBitInv($this->occupied,$c_start)) throw new Exception();
		$r_start = $c_start;
		while ($run) $run -= $this->getBitInv($this->continuation,++$r_start);
		$r = (unpack('i',substr($hash,8,8))[1]) & $this->r_mask;
		do if ($this->getRemainder($r_start++) === $r) return true;
		while ($this->getBit($this->continuation,$r_start));
		return false;
	}
	public function getBitInv(&$ds,$offset){
		$offset &= $this->q_mask;
		$word = $offset >> 3;
		$bit = chr(1 << ($offset & 7));
		return ($ds[$word] & $bit) === "\0";
	}
	public function getBit(&$ds,$offset){
		$offset &= $this->q_mask;
		$word = $offset >> 3;
		$bit = chr(1 << ($offset & 7));
		return ($ds[$word] & $bit) !== "\0";
	}
	public function setBit(&$ds,$offset,$value){
		$offset &= $this->q_mask;
		$word = $offset >> 3;
		$bit = $offset & 7;
		$ds[$word] = $ds[$word] & ~chr(1 << $bit) | chr($value << $bit);
	}
	public function getRemainder($offset){
		$start = ($offset & $this->q_mask) * $this->r;
		$start_word = $start >> 3;
		$start_mask = 0xFF >> ($start & 7);
		$end = $start + $this->r - 1;
		$end_word = $end >> 3;
		$end_bit = ($end & 7) + 1;
		$end_bit_rev = 8 - $end_bit;
		if ($start_word === $end_word){
			$mask = $start_mask >> $end_bit_rev << $end_bit_rev;
			return (ord($this->remainder[$start_word]) & $mask) >> $end_bit_rev;
		}
		$value = ord($this->remainder[$start_word]) & $start_mask;
		for ($i = $start_word + 1; $i < $end_word; $i++){
			$value <<= 8;
			$value |= ord($this->remainder[$i]);
		}
		$value <<= $end_bit;
		$value |= ord($this->remainder[$end_word]) >> $end_bit_rev;
		return $value;
	}
	public function setRemainder($offset,$value){
		$value &= $this->r_mask;
		$start = ($offset & $this->q_mask) * $this->r;
		$start_word = $start >> 3;
		$start_bit = $start & 7;
		$start_mask = chr(0xFF >> $start_bit);
		$end = $start + $this->r - 1;
		$end_word = $end >> 3;
		$end_bit = ($end & 7) + 1;
		$end_bit_rev = 8 - $end_bit;
		$end_mask = chr(0xFF >> $end_bit);
		if ($end_word === $start_word){
			$mask = $start_mask ^ $end_mask;
			$this->remainder[$start_word] = $this->remainder[$start_word] & ~$mask | chr($value << $end_bit_rev);
			return;
		}
		$segment = chr(($value & (0xFF >> $end_bit_rev)) << $end_bit_rev);
		$value >>= $end_bit;
		$this->remainder[$end_word] = $this->remainder[$end_word] & $end_mask | $segment;
		for ($i = $end_word - 1; $i > $start_word; $i--){
			$this->remainder[$i] = chr($value);
			$value >>= 8;
		}
		$mask = chr(0xFF << (8 - $start_bit));
		$segment = chr($value) & $start_mask;
		$this->remainder[$start_word] = $this->remainder[$start_word] & $mask | $segment;
	}
	public function display(){
		for ($i = 0; $i < (1 << $this->q); $i++){
			echo $i.'	';
			echo $this->getBit($this->occupied,$i) ? '1' : '0';
			echo $this->getBit($this->continuation,$i) ? '1' : '0';
			echo $this->getBit($this->shifted,$i) ? '1' : '0';
			echo ' ';
			echo $this->getRemainder($i);
			echo PHP_EOL;
		}
	}
}

?>