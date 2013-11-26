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
	private $q;
	private $q_mask;
	private $q_bytes;
	private $r;
	private $r_mask;
	private $r_bytes;
	private $h;
	private $slots;
	private $fast;
	private $slow;
	private $n;

	public function __construct($q,$r,$h='md5'){
		if ($q < 3) throw new Exception();
		else if ($q > 32) throw new Exception(); #maximum 5.7B slots
		if ($r > 63) throw new Exception(); #63 bits/remainder
		$this->q = $q;
		$this->q_mask = (1 << $q) - 1;
		$this->q_bytes = ($q >> 3) + (($q & 7) > 0);
		$this->r = $r;
		$this->r_mask = ((1 << ($r - 1)) - 1) + (1 << ($r - 1));
		$this->r_bytes = ($r >> 3) + (($r & 7) > 0);
		$this->h = $h;
		$this->slots = 2 << $q;
		$fast_size_bits = $this->slots * 3;
		$fast_size = ($fast_size_bits >> 3) + (($fast_size_bits & 7) > 0);
		$this->fast = str_repeat("\0",$fast_size);
		$slow_size_bits = $this->slots * $r;
		$slow_size = ($slow_size_bits >> 3) + (($slow_size_bits & 7) > 0);
		if ($slow_size >= (1 << 31)) throw new Exception(); #maximum 2GB-1
		$this->slow = str_repeat("\0",$slow_size);
		
		echo 'slots: '.$this->slots.PHP_EOL;
		echo 'quotient bits: '.$q.PHP_EOL;
		echo 'quotient buffer bytes: '.$fast_size.PHP_EOL;
		echo 'remainder buffer bytes: '.$slow_size.PHP_EOL;
	}
	public function add($key){
		$hash = hash($this->h,$key,true);
		$i = hexdec(unpack('H*',substr($hash,0,$this->q_bytes))[1]) & $this->q_mask;
		$r_hex = unpack('H*',substr($hash,$this->q_bytes,$this->r_bytes))[1];
		if ($this->r_bytes === 4 && $r_hex > '7fffffffffffffff') $r[0] = strval(hexdec($r[0]) - 8); #31b workaround
		$r = hexdec($r_hex) & $this->r_mask;
		$j = $i;
		
		$fingerprint = $this->getFingerprint($i);
		echo $key.'	Index: '.$i.'	Quotient: '.$fingerprint.'	Remainder: '.$r.'	';
		if ($fingerprint === 0){
			$this->setFingerprint($j,0b100);
			$this->setRemainder($j,$r);
			return true;
		}
		$run_create = ($fingerprint ^ 4) >> 2;
		echo ($run_create ? 'RC' : 'RE').'	';
		$runs = 1;
		
		#find cluster start
		while ($fingerprint ^ 4) {
			$j--;
			$fingerprint = $this->getFingerprint($j);
			$runs += $fingerprint & 4;
		}
		echo 'Runs: '.$runs.'	';
		echo 'CIndex: '.$j.'	';
		
		#find run start
		while ($runs > 1){
			$j++;
			$fingerprint = $this->getFingerprint($j);
			$runs -= (($fingerprint ^ 2) & 2) >> 1;
		}
		echo 'RIndex: '.$j.'	';
		
		do {
			$fingerprint = $this->getFingerprint($j);
			$remainder = $this->getRemainder($j);
			if (!$run_create && $remainder === $r) return false;
			$j++;
		} while ($fingerprint & 2);
		
		echo 'R+1Index: '.$j.'	';
		
		$fingerprint = $this->getFingerprint($j);
		$remainder = $this->getRemainder($j);
		$this->setFingerprint($j,($run_create << 1) | 0b01 | $fingerprint & 4);
		$this->setRemainder($j,$r);
		while ($fingerprint & 7){
			$j++;
			$fingerprint_next = $this->getFingerprint($j);
			$remainder_next = $this->getRemainder($j);
			$this->setFingerprint($j,(($fingerprint & 2) + 1) | ($fingerprint_next & 4));
			$this->setRemainder($j,$remainder);
			$fingerprint = $fingerprint_next;
			$remainder = $remainder_next;
		}
		if ($run_create) $this->setFingerprint($i,$this->getFingerprint($i) | 0b100);
		return true;
	}
	public function contains($key){
		$hash = hash($this->h,$key,true);
		$i = hexdec(unpack('H*',substr($hash,0,$this->q_bytes))[1]) & $this->q_mask;
		$r_hex = unpack('H*',substr($hash,$this->q_bytes,$this->r_bytes))[1];
		if ($this->r_bytes === 4 && $r_hex > '7fffffffffffffff') $r[0] = strval(hexdec($r[0]) - 8);
		$r = hexdec($r_hex) & $this->r_mask;
		$j = $i;
		
		$fingerprint = $this->getFingerprint($j);
		echo $key.'	Index: '.$i.'	Quotient: '.$fingerprint.'	Remainder: '.$r.'	';
		if ($fingerprint === 0) return false;
		$runs = ($fingerprint & 4) >> 2;
		if ($runs === 0) return false;
		
		#find cluster start
		while ($fingerprint ^ 4) {
			$j--;
			$fingerprint = $this->getFingerprint($j);
			$runs += $fingerprint & 4;
		}
		echo 'Runs: '.$runs.'	';
		echo 'CIndex: '.$j.'	';
		
		#find run start
		while ($runs > 1){
			$j++;
			$fingerprint = $this->getFingerprint($j);
			$runs -= (($fingerprint ^ 2) & 2) >> 1;
		}
		echo 'RIndex: '.$j.'	';
		
		#compare with remainders
		echo 'Remainder: '.$this->getRemainder($j).'	R:'.$r.'	';
		if ($this->getRemainder($j) === $r) return true;
		do {
			$j++;
			if ($this->getRemainder($j) === $r) return true;
			$fingerprint = $this->getFingerprint($j);
		} while($fingerprint & 2);
		return false;
	}
	public function remove($key){
		
		
	}
	private function calculateSlot($i){
		return (($i % $this->slots) + $this->slots) % $this->slots;
	}
	private function getSlot($i,$s,&$t){
		$i = $this->calculateSlot($i);
		$start = $i * $s;
		$start_byte = $start >> 3;
		$start_bit = $start & 7;
		$end = $start + $s - 1;
		$end_byte = $end >> 3;
		$end_bit = $end & 7;
		$result = ((ord($t[$start_byte]) << $start_bit) & 0b11111111) >> $start_bit;
		for ($j = $start_byte + 1; $j <= $end_byte; $j++) $result = ($result << 8) | (ord($t[$j]));
		return $result >> (7 - $end_bit);
	}
	private function setSlot($i,$b,$s,&$t){
		$msb = 1 << ($s - 1); #PHP 4GB
		if ($b < 0 || $b > ($msb - 1 + $msb)) throw new Exception();
		$i = $this->calculateSlot($i);
		$start = $i * $s;
		$start_byte = $start >> 3;
		$start_bit = $start & 7;
		$end = $start + $s - 1;
		$end_byte = $end >> 3;
		$end_bit = $end & 7;
		for ($j = $end_byte; $j >= $start_byte; $j--){
			$current = ord($t[$j]);
			$mask = 0b11111111;
			if ($j === $start_byte){
				$mask = $mask - ((1 << (8 - $start_bit)) - 1);
				$value = $b << ((7 - $end_bit)*($start_byte === $end_byte));
			} else {
				$value = ($b & ((1 << ($end_bit + 1)) - 1)) << (7 - $end_bit);
				$b >>= $end_bit + 1;
			}
			if ($j === $end_byte) $mask = $mask + ((1 << (7 - $end_bit)) - 1);
			$current = $current & $mask;
			$t[$j] = chr($current | $value);
		}
	}
	private function getFingerprint($i){
		return $this->getSlot($i,3,$this->fast);
	}
	private function setFingerprint($i,$b){
		return $this->setSlot($i,$b,3,$this->fast);
	}
	private function getRemainder($i){
		return $this->getSlot($i,$this->r,$this->slow);
	}
	private function setRemainder($i,$b){
		return $this->setSlot($i,$b,$this->r,$this->slow);
	}
	public function test(){
		#for ($i = 0; $i < 64; $i++) $this->setFingerprint($i,($i + 2) % 8);
		#for ($i = 0; $i < 64; $i++) $this->setRemainder($i,($i + 2));
		#for ($i = 0; $i < 72; $i++) echo $i.': '.$this->getFingerprint($i).' '.PHP_EOL;
		#for ($i = 0; $i < 72; $i++) echo $i.': '.$this->getRemainder($i).' '.PHP_EOL;
		for ($i = 0; $i < 256; $i++) echo ($this->add($i) ? 'Adding' : 'Exists').PHP_EOL;
		for ($i = 0; $i < 512; $i++) echo ($this->contains($i) ? 'Yes' : 'No').PHP_EOL;
	}
}

$qf = new QuotientFilter(16,8);
$qf->test();

?>