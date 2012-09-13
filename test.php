<?php 
error_reporting(E_ALL);
set_error_handler(function ($errno, $errstr, $errfile, $errline) { die($errno.': '.$errstr.' in '.$errfile.' on '.$errline); });

$config = parse_ini_file('test.ini',true);

foreach ($config['php'] as $key => $value) ini_set($key,$value);
foreach ($config['test']['include'] as $include) require_once $include;

echo '<pre>';

$qf = new QuotientFilter(34,29);
echo $qf->getArraySize(true).PHP_EOL;
echo $qf->getArraySize(false).PHP_EOL;
echo $qf->getSlotCount().PHP_EOL;
echo $qf->getSlotSize().PHP_EOL;
echo $qf->calculateProbability(2634).PHP_EOL;
echo $qf->calculateCapacity(0.01).PHP_EOL;
echo $qf->getLoadFactor().PHP_EOL;
echo $qf->getInfo(0.01);
echo '</pre>';

die();
?>
<form action="" method="POST">
<input type="submit" name="bench" value=""/>
<input type="submit" name="bench" value="2"/>
<input type="submit" name="bench" value="10"/>
<input type="submit" name="bench" value="U"/>
<input type="submit" name="bench" value="I"/>
</form>
<?php

if (isset($_POST['bench'])){
	$results = array();
	switch ($_POST['bench']){
		case '2':
			$results[] = array('N Elements','EProb','Capacity','FNeg','FPos','AProb');
			for ($i = 4; $i < 16; $i++){
				$n = (int)pow(2,$i);
				for ($p = 0.1; $p > 0.000001; $p /= 10){
					$result = array();
					$result[] = $n;
					$result[] = $p;
					
					$filter = $config['test']['class']::createFromProbability($n, $p);
					
					$result[] = $filter->calculateCapacity($p);
					
					$false_neg = 0;
					$false_pos = 0;
					
					$range = $n * 3;
					for ($k = 0; $k < $range; $k+= 3) $filter->add('T'.$k);
					$samples = $n * 9;
					for ($k = 0; $k < $samples; $k++) {
						if ($k % 3 == 0 && $k < $range) $false_neg += !$filter->contains('T'.$k);
						else $false_pos += $filter->contains('T'.$k);
					}
					
					$result[] = $false_neg;
					$result[] = $false_pos;
					$result[] = $false_pos / $samples;
					$result[] = ($false_pos / $samples < $p && $false_neg == 0) ? '<i>PASS</i>' : '<b>FAIL</b>';
					$results[] = $result;
				}
			}
			
		break;
		
		case '10':
			$results[] = array('N Elements','EProb','Capacity','FNeg','FPos','AProb');
			for ($i = 1; $i < 6; $i++){
				$n = (int)pow(10,$i);
				for ($p = 0.1; $p > 0.000001; $p /= 10){
					$result = array();
					$result[] = $n;
					$result[] = $p;
						
					$filter = $config['test']['class']::createFromProbability($n, $p);
						
					$result[] = $filter->calculateCapacity($p);
						
					$false_neg = 0;
					$false_pos = 0;
						
					$range = $n * 3;
					for ($k = 0; $k < $range; $k+= 3) $filter->add('T'.$k);
					$samples = $n * 9;
					for ($k = 0; $k < $samples; $k++) {
						if ($k % 3 == 0 && $k < $range) $false_neg += !$filter->contains('T'.$k);
						else $false_pos += $filter->contains('T'.$k);
					}
						
					$result[] = $false_neg;
					$result[] = $false_pos;
					$result[] = $false_pos / $samples;
					$result[] = ($false_pos / $samples < $p && $false_neg == 0) ? '<i>PASS</i>' : '<b>FAIL</b>';
					$results[] = $result;
				}
			}
		break;
		
		case 'U':
			$results[] = array('FNeg','FPos','EProb','AProb');
			$result = array();
			$capacity = 100000;
			$max = 175000;
			$p = 0.01;
			$filter1 = $config['test']['class']::createFromProbability($capacity, $p);
			$filter2 = $config['test']['class']::createFromProbability($capacity, $p);
			
			
			$samples = $capacity * 5;
			for ($i = 0; $i < $max; $i+=2) $filter1->add('K'.$i);
			for ($i = 0; $i < $max; $i+=3) $filter2->add('K'.$i);
			
			echo '<pre>'.$filter1->getInfo($p).'</pre>';
			echo '<pre>'.$filter2->getInfo($p).'</pre>';
			
			$filter3 = $config['test']['class']::getUnion($filter1,$filter2);
			
			$false_neg = 0;
			$false_pos = 0;
			
			for ($i = 0; $i < $samples; $i++){
				if (($i % 2 == 0 || $i % 3 == 0) && $i < $max) $false_neg += !$filter3->contains('K'.$i);
				else $false_pos += $filter3->contains('K'.$i);
			}
			
			echo '<pre>'.$filter3->getInfo($p).'</pre>';
			$result[] = $false_neg;
			$result[] = $false_pos;
			$result[] = $p;
			$result[] = $false_pos / $samples;
			$result[] = ($false_pos / $samples < $p && $false_neg == 0) ? '<i>PASS</i>' : '<b>FAIL</b>';
			
			$results[] = $result;
			try {
				echo 'Testing Merge ';
				$filterx = $config['test']['class']::createFromProbability($capacity, 0.1);
				for ($i = 0; $i < $capacity / 100; $i++) $filterx->add($i);
				$filterx = $config['test']['class']::getUnion($filterx,$filter3);
				echo '<b>FAIL</b>'.PHP_EOL;
			} catch (Exception $e){
				echo '<i>PASS</i>'.PHP_EOL;
			}
			
			$result = array();
			
			$filter1->unionWith($filter2);
			
			$false_neg = 0;
			$false_pos = 0;
				for ($i = 0; $i < $samples; $i++){
				if (($i % 2 == 0 || $i % 3 == 0) && $i < $max) $false_neg += !$filter1->contains('K'.$i);
				else $false_pos += $filter1->contains('K'.$i);
			}
			echo '<pre>'.$filter1->getInfo($p).'</pre>';
			$result[] = $false_neg;
			$result[] = $false_pos;
			$result[] = $p;
			$result[] = $false_pos / $samples;
			$result[] = ($false_pos / $samples < $p && $false_neg == 0) ? '<i>PASS</i>' : '<b>FAIL</b>';
			
			$results[] = $result;
		break;
		
		case 'I':
			$results[] = array('FNeg','FPos','EProb','AProb');
			$result = array();
			$capacity = 100000;
			$max = 300000;
			$p = 0.01;
			$filter1 = $config['test']['class']::createFromProbability($capacity, $p);
			$filter2 = $config['test']['class']::createFromProbability($capacity, $p);
			
			
			$samples = $capacity * 5;
			for ($i = 0; $i < $max; $i+=2) $filter1->add('K'.$i);
			for ($i = 0; $i < $max; $i+=3) $filter2->add('K'.$i);
			
			echo '<pre>'.$filter1->getInfo($p).'</pre>';
			echo '<pre>'.$filter2->getInfo($p).'</pre>';
			
			$filter3 = $config['test']['class']::getIntersection($filter1,$filter2);
			
			$false_neg = 0;
			$false_pos = 0;
			
			for ($i = 0; $i < $samples; $i++){
				if ($i % 2 == 0 && $i % 3 == 0 && $i < $max) $false_neg += !$filter3->contains('K'.$i);
				else $false_pos += $filter3->contains('K'.$i);
			}
			
			echo '<pre>'.$filter3->getInfo($p).'</pre>';
			$result[] = $false_neg;
			$result[] = $false_pos;
			$result[] = $p;
			$result[] = $false_pos / $samples;
			$result[] = ($false_pos / $samples < $p && $false_neg == 0) ? '<i>PASS</i>' : '<b>FAIL</b>';
			
			$results[] = $result;
			try {
				echo 'Testing Merge ';
				$filterx = $config['test']['class']::createFromProbability($capacity, 0.1);
				for ($i = 0; $i < $capacity / 100; $i++) $filterx->add($i);
				$filterx = $config['test']['class']::getIntersection($filterx,$filter3);
				echo '<b>FAIL</b>'.PHP_EOL;
			} catch (Exception $e){
				echo '<i>PASS</i>'.PHP_EOL;
			}
			
			$result = array();
			
			$filter1->intersectWith($filter2);
			
			$false_neg = 0;
			$false_pos = 0;
				for ($i = 0; $i < $samples; $i++){
				if ($i % 2 == 0 && $i % 3 == 0 && $i < $max) $false_neg += !$filter1->contains('K'.$i);
				else $false_pos += $filter1->contains('K'.$i);
			}
			echo '<pre>'.$filter1->getInfo($p).'</pre>';
			$result[] = $false_neg;
			$result[] = $false_pos;
			$result[] = $p;
			$result[] = $false_pos / $samples;
			$result[] = ($false_pos / $samples < $p && $false_neg == 0) ? '<i>PASS</i>' : '<b>FAIL</b>';
			
			$results[] = $result;
		break;
	}
	echo '<table>';
	array_walk($results,function($row){ echo '<tr><td>'.implode('</td><td>',$row).'</td></tr>'; });
	echo '</table>';
}
?>