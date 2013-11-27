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

#TODO: Fix reverse cycle for $j
#TODO: Check boundary conditions of integers

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
	const BYTE_MASK = 255;

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
		$this->slots = 1 << $q;
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
		if ($this->r_bytes === 8 && $r_hex > '7fffffffffffffff') $r_hex[0] = strval(hexdec($r_hex[0]) - 8); #31b workaround
		$r = hexdec($r_hex) & $this->r_mask;
		$j = $i;
		
		$fingerprint = $this->getFingerprint($i);
		#echo 'I: '.$i.' R: '.$this->printInteger($r,strlen(pow(2,$this->r) - 1)).' ';
		if ($fingerprint === 0){
			$this->setFingerprint($j,0b100);
			$this->setRemainder($j,$r);
			return true;
		}
		$run_exist = ($fingerprint & 4) >> 2;
		#echo ($run_exist ? 'RUN_E' : 'RUN_C').' ';
		$runs = 1;
		
		#find cluster start
		while ($fingerprint ^ 4) {
			$j--;
			$fingerprint = $this->getFingerprint($j);
			$runs += ($fingerprint & 4) >> 2;
		}
		#echo 'RUNS: '.$runs.' ';
		#echo 'C_I: '.$j.' ';
		
		#find run start
		while ($runs > 1){
			$j++;
			$fingerprint = $this->getFingerprint($j);
			$runs -= (($fingerprint ^ 2) & 2) >> 1;
		}
		#echo 'R_I: '.$j.' ';
		
		#check for remainder
		if ($run_exist){
			//$fingerprint = $this->getFingerprint($j);
			do {
				$fingerprint = $this->getFingerprint($j);
				$remainder = $this->getRemainder($j);
				if ($run_exist && $remainder === $r) return false;
				$j++;
				//$fingerprint = $this->getFingerprint($j);
			} while ($fingerprint & 2);
		}
		#echo 'R+1_I: '.$j.' ';
		
		#shift cluster
		
		$fingerprint = $this->getFingerprint($j);
		$remainder = $this->getRemainder($j);
		$this->setFingerprint($j,$fingerprint & 4 | (($run_exist) << 1) | 1 );
		$this->setRemainder($j,$r);
		while ($fingerprint){
			$j++;
			$fingerprint_next = $this->getFingerprint($j);
			$remainder_next = $this->getRemainder($j);
			$k = (($fingerprint & 2) + 1) | ($fingerprint_next & 4);
			#echo "{ $j:$fingerprint:$fingerprint_next:$k } ";
			$this->setFingerprint($j,$k);
			$this->setRemainder($j,$remainder);
			$fingerprint = $fingerprint_next;
			$remainder = $remainder_next;
		}
		if (!$run_exist) $this->setFingerprint($i,$this->getFingerprint($i) | 0b100);
		return true;
	}
	public function contains($key){
		$hash = hash($this->h,$key,true);
		$i = hexdec(unpack('H*',substr($hash,0,$this->q_bytes))[1]) & $this->q_mask;
		$r_hex = unpack('H*',substr($hash,$this->q_bytes,$this->r_bytes))[1];
		if ($this->r_bytes === 8 && $r_hex > '7fffffffffffffff') $r_hex[0] = strval(hexdec($r_hex[0]) - 8); #31b workaround
		$r = hexdec($r_hex) & $this->r_mask;
		$j = $i;
		$fingerprint = $this->getFingerprint($j);
		#echo 'I: '.$i.' R: '.$this->printInteger($r,strlen(pow(2,$this->r) - 1)).' ';
		if (!($fingerprint & 4)) return false;
		$runs = 1;
		
		#find cluster start
		while ($fingerprint ^ 4) {
			$j--;
			$fingerprint = $this->getFingerprint($j);
			$runs += ($fingerprint & 4) >> 2;
		}
		#echo 'RUNS: '.$runs.'	';
		#echo 'C_I: '.$j.' ';
		
		#find run start
		while ($runs > 1){
			$j++;
			$fingerprint = $this->getFingerprint($j);
			$runs -= (($fingerprint ^ 2) & 2) >> 1;
		}
		#echo 'R_I: '.$j.' ';
		
		#compare with remainders
		#echo 'R_CUR: '.$this->getRemainder($j).' R_NEW:'.$r.'	';
		#if ($this->getRemainder($j) === $r) return true;
		do {
			if ($this->getRemainder($j) === $r) return true;
			$j++;
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
		#echo $start.','.$start_byte.','.$start_bit.','.$end.','.$end_byte.','.$end_bit.PHP_EOL;
		if ($start_byte === $end_byte) return (ord($t[$start_byte]) >> (7 - $end_bit)) & ((1 << $s) - 1);
		for ($j = $start_byte; $j <= $end_byte; $j++){
			#echo $this->printBinary(ord($t[$j])).PHP_EOL;
			if ($j === $start_byte){
				$value = (ord($t[$j]) << $start_bit & self::BYTE_MASK) >> $start_bit;
			} else if ($j === $end_byte){
				$value = ($value << ($end_bit + 1)) | (ord($t[$j]) >> (7 - $end_bit));
			} else {
				$value = ($value << 8) | ord($t[$j]);
			}
			#echo $value.PHP_EOL;
		}
		return $value;
	}
	private function setSlot($i,$b,$s,&$t){
		#$msb = 1 << ($s - 1); #PHP 4GB
		if ($b < 0) throw new Exception();
		$i = $this->calculateSlot($i);
		$start = $i * $s;
		$start_byte = $start >> 3;
		$start_bit = $start & 7;
		$end = $start + $s - 1;
		$end_byte = $end >> 3;
		$end_bit = ($end & 7) + 1;
		#echo PHP_EOL.$start.','.$start_byte.','.$start_bit.','.$end.','.$end_byte.','.$end_bit;
		#echo PHP_EOL.$this->printBinary(substr($t,$start_byte,3));
		#echo PHP_EOL;
		if ($start_byte === $end_byte){
			$current = ord($t[$start_byte]);
			$mask = self::BYTE_MASK & (self::BYTE_MASK << (8 - $start_bit)) | (self::BYTE_MASK >> $end_bit); #11000011
			$value = $b << (8 - $end_bit);
			$current = $current & $mask;
			$t[$start_byte] = chr($current | $value);
		} else {
			for ($j = $end_byte; $j >= $start_byte; $j--){
				$current = ord($t[$j]);
				$mask = self::BYTE_MASK;
				if ($j === $start_byte){
					$value = $b & (self::BYTE_MASK >> $start_bit); #00011111
					$mask &= self::BYTE_MASK << (8 - $start_bit); #11100000
				} else if ($j === $end_byte){
					$value = ($b << (8 - $end_bit)) & self::BYTE_MASK; #11100000
					$b >>= $end_bit;
					$mask &= self::BYTE_MASK >> $end_bit; #00011111
				} else {
					$mask = 0;
					$value = $b & self::BYTE_MASK;
					$b >>= 8;
				}
				$current = $current & $mask;
				#echo PHP_EOL.$this->printBinary($current,8).PHP_EOL.$this->printBinary($value,8).PHP_EOL;
				$t[$j] = chr($current | $value);
			}
		}
		#echo PHP_EOL.$this->printBinary(substr($t,$start_byte,3));
		#echo PHP_EOL;
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
		#echo "AT $i IN $b OUT ";
		return $this->setSlot($i,$b,$this->r,$this->slow);
		#echo $this->getSlot($i,$this->r,$this->slow).PHP_EOL;
		#return $this->setSlot($i,$b,$this->r,$this->slow);
	}
	public function test(){
		#for ($i = 0; $i < 64; $i++) $this->setFingerprint($i,($i + 2) % 8);
		#for ($i = 0; $i < 64; $i++) $this->setRemainder($i,($i + 2));
		#for ($i = 0; $i < 72; $i++) echo $i.': '.$this->getFingerprint($i).' '.PHP_EOL;
		#for ($i = 0; $i < 72; $i++) echo $i.': '.$this->getRemainder($i).' '.PHP_EOL;
		#echo 'MAX : '.$this->printBinary(PHP_INT_MAX).PHP_EOL;
		#$this->setSlot(0,0,$this->r,$this->slow);
		#$this->setSlot(1,1,$this->r,$this->slow);
		#$this->setSlot(2,2,$this->r,$this->slow);
		#$this->setSlot(3,3,$this->r,$this->slow);
		#$this->setSlot(4,65534,$this->r,$this->slow);
		#$this->setSlot(5,65535,$this->r,$this->slow);
		#echo $this->printBinary(substr($this->slow,0,12)).PHP_EOL;
		#echo $this->printBinary($this->getSlot(0,$this->r,$this->slow)).PHP_EOL;
		#echo $this->printBinary($this->getSlot(1,$this->r,$this->slow)).PHP_EOL;
		#echo $this->printBinary($this->getSlot(2,$this->r,$this->slow)).PHP_EOL;
		#echo $this->printBinary($this->getSlot(3,$this->r,$this->slow)).PHP_EOL;
		#echo $this->printBinary($this->getSlot(4,$this->r,$this->slow)).PHP_EOL;
		#echo $this->printBinary($this->getSlot(5,$this->r,$this->slow)).PHP_EOL;
		#echo $this->printBinary($test).PHP_EOL;
		#echo $this->printBinary(substr($this->slow,7,9)).PHP_EOL;
		#echo $this->printBinary($this->getSlot(1,$this->r,$this->slow)).PHP_EOL;
		#echo $this->printBinary($test).PHP_EOL;
		for ($i = 0; $i < $this->slots; $i++){
			echo $i.'	'.($this->add($i) ? 'A' : 'E').PHP_EOL;
			#if ($i > $this->slots - 3){
				#for ($j = 0; $j < $this->slots; $j++) echo $this->printBinary($this->getFingerprint($j),$this->q).' ';
				#echo PHP_EOL;
				#for ($j = 0; $j < $this->slots; $j++) echo $this->printInteger($this->getRemainder($j),strlen(pow(2,$this->r) - 1)).' ';
				#echo PHP_EOL;
				#echo $this->printBinary(substr($this->fast,0,3));
				#echo PHP_EOL.PHP_EOL;
			#}
		}
		echo PHP_EOL;
		for ($i = 0; $i < $this->slots * 2; $i++){
			echo $i.'	'.($this->contains($i) ? 'Yes' : 'No').PHP_EOL;
		}
	}
	private function printBinary($key,$length = 0){
		if (is_int($key)){
			$array = [];
			#if ($adjust) $key <<= abs($adjust);
			do {
				$array[] = str_pad(decbin($key & self::BYTE_MASK),$length ?: 8,'0',STR_PAD_LEFT);
				$key >>= 8;
			} while ($key);
			return implode(array_reverse($array));
		} else if (is_string($key)){
			return implode(array_map(function($v) use ($length){ return str_pad(decbin(ord($v)),$length ?: 8,'0',STR_PAD_LEFT);},str_split($key)));
		} else {
			
		}
	}
	private function printInteger($key,$length = 0){
		if (is_int($key)){
			return str_pad($key,$length,'0',STR_PAD_LEFT);
		}
	}
}

$qf = new QuotientFilter(6,63);
$qf->test();

?>