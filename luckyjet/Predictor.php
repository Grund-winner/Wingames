<?php
/**
 * DVYS AI - Moteur de Prediction Avance v2.0
 * 
 * Algorithme multi-couches d'analyse de patterns pour jeux crash.
 * 8 modules d'analyse combines avec ponderation adaptative.
 * 
 * Modules :
 *  1. Analyse de Tendance (EMA/SMA)
 *  2. Detection de Series (Streak)
 *  3. Analyse de Volatilite (Bollinger)
 *  4. Pattern Matching (N-grams)
 *  5. Chaine de Markov (Transitions)
 *  6. Reversion a la Moyenne
 *  7. Detection de Cycles
 *  8. Score de Momentum
 */

class CrashPredictor
{
    private array $history;
    private float $minPred;
    private float $maxPred;
    private const MIN_ROUNDS = 5;

    private array $weights = [
        'trend'        => 0.12,
        'streak'       => 0.20,
        'volatility'   => 0.08,
        'pattern'      => 0.16,
        'markov'       => 0.16,
        'meanReversion'=> 0.10,
        'cycle'        => 0.06,
        'momentum'     => 0.12,
    ];

    private array $moduleResults = [];

    public function __construct(array $history, float $minPred = 1.00, float $maxPred = 25.00)
    {
        $this->history = array_values(array_filter(
            $history,
            fn($v) => is_numeric($v) && $v > 0
        ));
        $this->minPred = $minPred;
        $this->maxPred = $maxPred;
    }

    public function predict(): array
    {
        if (count($this->history) < self::MIN_ROUNDS) {
            return $this->fallbackPrediction();
        }

        $this->moduleResults['trend']         = $this->analyzeTrend();
        $this->moduleResults['streak']        = $this->analyzeStreak();
        $this->moduleResults['volatility']    = $this->analyzeVolatility();
        $this->moduleResults['pattern']       = $this->analyzePatterns();
        $this->moduleResults['markov']        = $this->analyzeMarkov();
        $this->moduleResults['meanReversion'] = $this->analyzeMeanReversion();
        $this->moduleResults['cycle']         = $this->analyzeCycles();
        $this->moduleResults['momentum']      = $this->analyzeMomentum();

        $weights = $this->adaptWeights();
        $combined = $this->combineModules($weights);
        $prediction = $this->generateFinalPrediction($combined);
        $signals = $this->collectSignals();

        return [
            'prediction' => round($prediction, 2),
            'confidence' => round($combined['confidence'], 2),
            'modules'    => $this->summarizeModules(),
            'signals'    => $signals,
            'analysis'   => [
                'rounds_analyzed'  => count($this->history),
                'avg'              => round($this->avg($this->history), 2),
                'std_dev'          => round($this->stdDev($this->history), 2),
                'last_5'           => array_slice($this->history, -5),
                'direction'        => $combined['direction'],
            ],
        ];
    }

    // ================================================================
    //  MODULE 1 : ANALYSE DE TENDANCE
    // ================================================================

    private function analyzeTrend(): array
    {
        $h = $this->history;
        $n = count($h);

        $ema5  = $this->ema($h, 5);
        $ema10 = $n >= 10 ? $this->ema($h, 10) : $ema5;
        $ema20 = $n >= 20 ? $this->ema($h, 20) : $ema10;
        $sma10 = $n >= 10 ? $this->sma($h, 10) : $ema5;

        $shortAboveLong = $ema5 > $ema10;
        $macd = $ema5 - $ema10;

        $direction = 0;
        $strength = 0;

        if ($macd > 0.3) {
            $direction = 1;
            $strength = min(abs($macd) / 2.0, 1.0);
        } elseif ($macd < -0.3) {
            $direction = -1;
            $strength = min(abs($macd) / 2.0, 1.0);
        }

        $suggested = $ema5 + ($macd * 0.3);

        return [
            'suggested'  => $this->clamp($suggested),
            'direction'  => $direction,
            'strength'   => $strength,
            'confidence' => min(0.5 + $strength * 0.3, 0.9),
            'ema5'       => round($ema5, 3),
            'ema10'      => round($ema10, 3),
            'macd'       => round($macd, 3),
        ];
    }

    // ================================================================
    //  MODULE 2 : DETECTION DE SERIES
    // ================================================================

    private function analyzeStreak(): array
    {
        $h = $this->history;
        $categories = $this->categorize($h);

        $last = $categories[count($categories) - 1];
        $streakLen = 1;
        for ($i = count($categories) - 2; $i >= 0; $i--) {
            if ($categories[$i] === $last) {
                $streakLen++;
            } else {
                break;
            }
        }

        $recent = array_slice($categories, -20);
        $counts = array_count_values($recent);
        $total = count($recent);

        $lowPct  = ($counts['low'] ?? 0) / $total;
        $midPct  = ($counts['mid'] ?? 0) / $total;
        $highPct = ($counts['high'] ?? 0) / $total;

        $suggested = 0;
        $confidence = 0.5;
        $signal = '';

        if ($streakLen >= 4) {
            if ($last === 'low') {
                $suggested = $this->randomInRange(2.50, 5.50);
                $confidence = 0.75 + min($streakLen * 0.03, 0.15);
                $signal = "long_low_streak_break";
            } elseif ($last === 'high') {
                $suggested = $this->randomInRange(1.20, 2.20);
                $confidence = 0.70 + min($streakLen * 0.03, 0.15);
                $signal = "long_high_streak_correction";
            } else {
                $suggested = $this->randomInRange(1.30, 2.50);
                $confidence = 0.60;
                $signal = "long_mid_streak_end";
            }
        } elseif ($streakLen === 3) {
            if ($last === 'low') {
                $suggested = $this->randomInRange(2.00, 4.50);
                $confidence = 0.65;
                $signal = "triple_low_rebound";
            } elseif ($last === 'high') {
                $suggested = $this->randomInRange(1.20, 2.00);
                $confidence = 0.60;
                $signal = "triple_high_pullback";
            } else {
                $suggested = $this->randomInRange(1.50, 3.00);
                $confidence = 0.55;
                $signal = "triple_mid_transition";
            }
        } elseif ($streakLen === 2) {
            if ($last === 'low') {
                $suggested = $this->randomInRange(1.80, 3.50);
                $confidence = 0.55;
                $signal = "double_low_possible_rebound";
            } elseif ($last === 'high') {
                $suggested = $this->randomInRange(1.30, 2.30);
                $confidence = 0.55;
                $signal = "double_high_cooling";
            } else {
                $suggested = $this->randomInRange(1.60, 2.80);
                $confidence = 0.50;
                $signal = "double_mid_continue";
            }
        } else {
            if ($lowPct > 0.65) {
                $suggested = $this->randomInRange(2.00, 4.00);
                $confidence = 0.60;
                $signal = "low_dominance_rebound";
            } elseif ($highPct > 0.40) {
                $suggested = $this->randomInRange(1.30, 2.50);
                $confidence = 0.55;
                $signal = "high_dominance_correction";
            } else {
                $suggested = $this->randomInRange(1.50, 3.00);
                $confidence = 0.45;
                $signal = "no_streak_normal";
            }
        }

        return [
            'suggested'        => $this->clamp($suggested),
            'confidence'       => $confidence,
            'signal'           => $signal,
            'streak_length'    => $streakLen,
            'streak_category'  => $last,
            'low_pct'          => round($lowPct, 3),
            'mid_pct'          => round($midPct, 3),
            'high_pct'         => round($highPct, 3),
        ];
    }

    // ================================================================
    //  MODULE 3 : ANALYSE DE VOLATILITE
    // ================================================================

    private function analyzeVolatility(): array
    {
        $h = array_slice($this->history, -20);
        $n = count($h);

        if ($n < 5) {
            return ['suggested' => $this->clamp(2.0), 'confidence' => 0.3, 'regime' => 'unknown'];
        }

        $mean = $this->avg($h);
        $std  = $this->stdDev($h);

        $upperBand = $mean + (2 * $std);
        $lowerBand = $mean - (2 * $std);
        $last = $h[$n - 1];
        $percentB = $std > 0 ? ($last - $lowerBand) / ($upperBand - $lowerBand) : 0.5;

        $regime = 'normal';
        if ($std < 0.5) $regime = 'low';
        elseif ($std > 2.0) $regime = 'high';

        $suggested = 0;
        $confidence = 0.5;

        if ($percentB < 0.15) {
            $suggested = $mean + ($std * 0.5);
            $confidence = 0.60;
        } elseif ($percentB > 0.85) {
            $suggested = $mean - ($std * 0.2);
            $confidence = 0.55;
        } else {
            $suggested = $mean + ($this->randomInRange(-0.3, 0.5) * $std);
            $confidence = 0.45;
        }

        if ($regime === 'low') {
            $suggested += 0.2;
            $confidence += 0.05;
        } elseif ($regime === 'high') {
            $suggested = $mean;
            $confidence -= 0.05;
        }

        return [
            'suggested'  => $this->clamp($suggested),
            'confidence' => $this->clampConf($confidence),
            'regime'     => $regime,
            'std_dev'    => round($std, 3),
            'upper_band' => round($upperBand, 3),
            'lower_band' => round($lowerBand, 3),
            'percent_b'  => round($percentB, 3),
        ];
    }

    // ================================================================
    //  MODULE 4 : PATTERN MATCHING (N-grams)
    // ================================================================

    private function analyzePatterns(): array
    {
        $h = $this->history;
        $n = count($h);

        if ($n < 10) {
            return ['suggested' => $this->clamp(2.0), 'confidence' => 0.3, 'matched_pattern' => null];
        }

        $cats = $this->categorize($h);
        $bestMatch = null;
        $bestConfidence = 0;
        $predictedCat = 'mid';

        foreach ([2, 3, 4, 5] as $len) {
            if ($n < $len * 3) continue;

            $currentPattern = array_slice($cats, -$len);
            $nextValues = [];

            for ($i = 0; $i <= $n - $len - 1; $i++) {
                $window = array_slice($cats, $i, $len);
                if ($window === $currentPattern && $i + $len < $n) {
                    $nextValues[] = $cats[$i + $len];
                }
            }

            if (count($nextValues) >= 2) {
                $freq = array_count_values($nextValues);
                arsort($freq);
                $topCat = array_key_first($freq);
                $matchRate = $freq[$topCat] / count($nextValues);
                $weight = $len * 0.05;
                $score = $matchRate * $weight;

                if ($score > $bestConfidence) {
                    $bestConfidence = $score;
                    $bestMatch = $len . '-gram: ' . implode(',', $currentPattern);
                    $predictedCat = $topCat;
                }
            }
        }

        $suggested = match ($predictedCat) {
            'low'  => $this->randomInRange(1.20, 1.90),
            'mid'  => $this->randomInRange(2.00, 4.00),
            'high' => $this->randomInRange(4.50, 8.00),
            default => $this->randomInRange(1.50, 3.00),
        };

        return [
            'suggested'       => $this->clamp($suggested),
            'confidence'      => $this->clampConf(0.4 + $bestConfidence * 0.4),
            'matched_pattern' => $bestMatch,
            'predicted_cat'   => $predictedCat,
        ];
    }

    // ================================================================
    //  MODULE 5 : CHAINE DE MARKOV
    // ================================================================

    private function analyzeMarkov(): array
    {
        $h = $this->history;
        $cats = $this->categorize($h);
        $n = count($cats);

        if ($n < 10) {
            return ['suggested' => $this->clamp(2.0), 'confidence' => 0.3, 'transition' => null];
        }

        $transitions = ['low' => ['low' => 0, 'mid' => 0, 'high' => 0], 'mid' => ['low' => 0, 'mid' => 0, 'high' => 0], 'high' => ['low' => 0, 'mid' => 0, 'high' => 0]];

        for ($i = 0; $i < $n - 1; $i++) {
            $from = $cats[$i];
            $to   = $cats[$i + 1];
            $transitions[$from][$to]++;
        }

        foreach ($transitions as $from => &$toCounts) {
            $total = array_sum($toCounts) + 3;
            foreach ($toCounts as $to => &$count) {
                $count = ($count + 1) / $total;
            }
        }

        $transitions2 = [];
        for ($i = 0; $i < $n - 2; $i++) {
            $key = $cats[$i] . ',' . $cats[$i + 1];
            $next = $cats[$i + 2];
            if (!isset($transitions2[$key])) {
                $transitions2[$key] = ['low' => 0, 'mid' => 0, 'high' => 0];
            }
            $transitions2[$key][$next]++;
        }

        foreach ($transitions2 as $key => &$counts) {
            $total = array_sum($counts) + 3;
            foreach ($counts as $cat => &$count) {
                $count = ($count + 1) / $total;
            }
        }

        $currentState = $cats[$n - 1];
        $probs = $transitions[$currentState];

        if ($n >= 2) {
            $bigramKey = $cats[$n - 2] . ',' . $cats[$n - 1];
            if (isset($transitions2[$bigramKey])) {
                $bigramProbs = $transitions2[$bigramKey];
                $combined = [];
                foreach (['low', 'mid', 'high'] as $cat) {
                    $combined[$cat] = ($probs[$cat] * 0.4) + ($bigramProbs[$cat] * 0.6);
                }
                $probs = $combined;
            }
        }

        arsort($probs);
        $predictedCat = array_key_first($probs);
        $probValue = $probs[$predictedCat];

        $suggested = match ($predictedCat) {
            'low'  => $this->randomInRange(1.15, 1.85),
            'mid'  => $this->randomInRange(1.90, 3.80),
            'high' => $this->randomInRange(4.00, 7.50),
            default => $this->randomInRange(1.50, 3.00),
        };

        $confidence = 0.35 + ($probValue * 0.45);

        return [
            'suggested'    => $this->clamp($suggested),
            'confidence'   => $this->clampConf($confidence),
            'transition'   => "{$currentState} -> {$predictedCat} (" . round($probValue * 100, 1) . "%)",
            'current_state'=> $currentState,
            'probabilities'=> array_map(fn($v) => round($v, 3), $probs),
        ];
    }

    // ================================================================
    //  MODULE 6 : REVERSION A LA MOYENNE
    // ================================================================

    private function analyzeMeanReversion(): array
    {
        $h = $this->history;
        $n = count($h);

        $shortMean = $this->avg(array_slice($h, -5));
        $mediumMean = $n >= 10 ? $this->avg(array_slice($h, -10)) : $shortMean;
        $longMean = $n >= 20 ? $this->avg(array_slice($h, -20)) : $mediumMean;

        $last = $h[$n - 1];

        $shortForce = ($shortMean - $last) / max($shortMean, 0.01);
        $mediumForce = ($mediumMean - $last) / max($mediumMean, 0.01);
        $longForce = ($longMean - $last) / max($longMean, 0.01);

        $reversionScore = ($shortForce * 0.50) + ($mediumForce * 0.30) + ($longForce * 0.20);

        $target = $shortMean + ($mediumMean - $shortMean) * 0.3;
        $adjustment = $reversionScore * 0.4;
        $suggested = $last + ($adjustment * abs($last - $target));

        $distance = abs($last - $shortMean) / max($shortMean, 0.01);
        $confidence = min(0.4 + $distance * 0.3, 0.85);

        $direction = $reversionScore > 0.05 ? 'up' : ($reversionScore < -0.05 ? 'down' : 'neutral');

        return [
            'suggested'     => $this->clamp($suggested),
            'confidence'    => $this->clampConf($confidence),
            'direction'     => $direction,
            'reversion_score' => round($reversionScore, 4),
            'short_mean'    => round($shortMean, 3),
            'medium_mean'   => round($mediumMean, 3),
            'long_mean'     => round($longMean, 3),
            'distance'      => round($distance, 4),
        ];
    }

    // ================================================================
    //  MODULE 7 : DETECTION DE CYCLES
    // ================================================================

    private function analyzeCycles(): array
    {
        $h = $this->history;
        $cats = $this->categorize($h);
        $n = count($cats);

        if ($n < 15) {
            return ['suggested' => $this->clamp(2.0), 'confidence' => 0.25, 'cycle_detected' => false, 'cycle_length' => 0];
        }

        $bestCycle = 0;
        $bestScore = 0;
        $nextInCycle = null;

        foreach (range(3, min(10, (int)($n / 2))) as $cycleLen) {
            $matches = 0;
            $total = 0;

            for ($i = $cycleLen; $i < $n; $i++) {
                $current = $cats[$i];
                $past = $cats[$i - $cycleLen];
                $total++;
                if ($current === $past) {
                    $matches++;
                }
            }

            if ($total > 0) {
                $score = $matches / $total;
                if ($score > $bestScore && $score > 0.35) {
                    $bestScore = $score;
                    $bestCycle = $cycleLen;
                }
            }
        }

        $cycleDetected = $bestCycle > 0 && $bestScore > 0.35;

        if ($cycleDetected) {
            $pos = $n % $bestCycle;
            $prevCycleStart = max(0, $n - $bestCycle);
            if ($prevCycleStart + $pos < $n) {
                $nextInCycle = $h[$prevCycleStart + $pos];
            }

            $suggested = $nextInCycle ? $nextInCycle + $this->randomInRange(-0.3, 0.3) : $this->avg($h);
            $confidence = 0.4 + ($bestScore * 0.35);
        } else {
            $suggested = $this->avg($h);
            $confidence = 0.3;
        }

        return [
            'suggested'      => $this->clamp($suggested),
            'confidence'     => $this->clampConf($confidence),
            'cycle_detected' => $cycleDetected,
            'cycle_length'   => $bestCycle,
            'cycle_score'    => round($bestScore, 3),
        ];
    }

    // ================================================================
    //  MODULE 8 : SCORE DE MOMENTUM
    // ================================================================

    private function analyzeMomentum(): array
    {
        $h = $this->history;
        $n = count($h);

        if ($n < 6) {
            return ['suggested' => $this->clamp(2.0), 'confidence' => 0.3, 'momentum' => 'neutral'];
        }

        $changes = [];
        for ($i = 1; $i < $n; $i++) {
            $changes[] = $h[$i] - $h[$i - 1];
        }

        $shortMom = array_sum(array_slice($changes, -3)) / min(3, count($changes));
        $medMom = count($changes) >= 6 ? array_sum(array_slice($changes, -6)) / 6 : $shortMom;

        $acceleration = $shortMom - $medMom;

        $momentum = 'neutral';
        if ($shortMom > 0.3) {
            $momentum = 'strong_up';
        } elseif ($shortMom > 0.1) {
            $momentum = 'up';
        } elseif ($shortMom < -0.3) {
            $momentum = 'strong_down';
        } elseif ($shortMom < -0.1) {
            $momentum = 'down';
        }

        $last = $h[$n - 1];

        if ($momentum === 'strong_up' && $acceleration > 0) {
            $suggested = $last + $shortMom * 0.6;
            $confidence = 0.55;
        } elseif ($momentum === 'strong_down' && $acceleration < 0) {
            $suggested = $last + $shortMom * 0.4;
            $confidence = 0.55;
        } elseif ($acceleration < -0.2 && $momentum === 'up') {
            $suggested = $last * 0.95;
            $confidence = 0.50;
        } elseif ($acceleration > 0.2 && $momentum === 'down') {
            $suggested = $last + abs($shortMom) * 0.3;
            $confidence = 0.50;
        } else {
            $suggested = $this->avg(array_slice($h, -5));
            $confidence = 0.40;
        }

        return [
            'suggested'    => $this->clamp($suggested),
            'confidence'   => $this->clampConf($confidence),
            'momentum'     => $momentum,
            'acceleration' => round($acceleration, 4),
            'short_mom'    => round($shortMom, 3),
            'med_mom'      => round($medMom, 3),
        ];
    }

    // ================================================================
    //  COMBINAISON ET PONDERATION ADAPTATIVE
    // ================================================================

    private function adaptWeights(): array
    {
        $n = count($this->history);
        $w = $this->weights;

        if ($n < 15) {
            $w['pattern'] *= 0.5;
            $w['markov'] *= 0.7;
            $w['cycle'] *= 0.3;
            $w['streak'] *= 1.3;
            $w['meanReversion'] *= 1.2;
        }

        if ($n >= 30) {
            $w['pattern'] *= 1.2;
            $w['markov'] *= 1.15;
            $w['cycle'] *= 1.1;
        }

        $sum = array_sum($w);
        foreach ($w as $k => &$v) {
            $v = $v / $sum;
        }

        return $w;
    }

    private function combineModules(array $weights): array
    {
        $weightedSum = 0;
        $confidenceSum = 0;
        $directionVotes = ['up' => 0, 'down' => 0, 'neutral' => 0];
        $totalWeight = 0;

        foreach ($this->moduleResults as $name => $result) {
            $weight = $weights[$name] ?? 0;
            $totalWeight += $weight;
            $weightedSum += $result['suggested'] * $weight;
            $confidenceSum += $result['confidence'] * $weight;

            $dir = $result['direction'] ?? ($result['momentum'] ?? 'neutral');
            if ($dir === 1 || $dir === 'up' || $dir === 'strong_up') {
                $directionVotes['up'] += $weight;
            } elseif ($dir === -1 || $dir === 'down' || $dir === 'strong_down') {
                $directionVotes['down'] += $weight;
            } else {
                $directionVotes['neutral'] += $weight;
            }
        }

        asort($directionVotes);
        $direction = array_key_last($directionVotes);

        return [
            'weighted_suggestion' => $totalWeight > 0 ? $weightedSum / $totalWeight : 2.0,
            'confidence'          => $totalWeight > 0 ? $confidenceSum / $totalWeight : 0.5,
            'direction'           => $direction,
            'direction_votes'     => $directionVotes,
        ];
    }

    private function generateFinalPrediction(array $combined): float
    {
        $base = $combined['weighted_suggestion'];
        $conf = $combined['confidence'];

        $variance = $base * 0.05;
        $noise = $this->randomInRange(-$variance, $variance);
        $noise *= (1 - $conf * 0.5);

        $final = $base + $noise;

        return $this->clamp($final);
    }

    private function collectSignals(): array
    {
        $signals = [];

        if (!empty($this->moduleResults['streak']['signal'])) {
            $signals[] = $this->moduleResults['streak']['signal'];
        }

        if (!empty($this->moduleResults['pattern']['matched_pattern'])) {
            $signals[] = 'pattern_matched';
        }

        if (!empty($this->moduleResults['cycle']['cycle_detected'])) {
            $signals[] = 'cycle_' . $this->moduleResults['cycle']['cycle_length'];
        }

        $mom = $this->moduleResults['momentum']['momentum'] ?? '';
        if (in_array($mom, ['strong_up', 'strong_down'])) {
            $signals[] = $mom . '_momentum';
        }

        $vol = $this->moduleResults['volatility']['regime'] ?? '';
        if ($vol === 'high') {
            $signals[] = 'high_volatility';
        } elseif ($vol === 'low') {
            $signals[] = 'low_volatility';
        }

        return $signals;
    }

    private function summarizeModules(): array
    {
        $summary = [];
        foreach ($this->moduleResults as $name => $result) {
            $summary[$name] = [
                'suggested'  => round($result['suggested'], 2),
                'confidence' => round($result['confidence'], 2),
            ];
        }
        return $summary;
    }

    private function fallbackPrediction(): array
    {
        $n = count($this->history);
        $avg = $n > 0 ? $this->avg($this->history) : 2.0;

        return [
            'prediction' => round($this->clamp($avg + $this->randomInRange(-0.3, 0.3)), 2),
            'confidence' => 0.25,
            'modules'    => [],
            'signals'    => ['insufficient_data'],
            'analysis'   => [
                'rounds_analyzed' => $n,
                'avg'             => round($avg, 2),
                'direction'       => 'neutral',
            ],
        ];
    }

    // ================================================================
    //  UTILITAIRES MATHEMATIQUES
    // ================================================================

    private function avg(array $values): float
    {
        if (empty($values)) return 0.0;
        return array_sum($values) / count($values);
    }

    private function stdDev(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0.0;
        $mean = $this->avg($values);
        $variance = array_sum(array_map(fn($v) => pow($v - $mean, 2), $values)) / ($n - 1);
        return sqrt($variance);
    }

    private function sma(array $values, int $period): float
    {
        $slice = array_slice($values, -$period);
        return count($slice) > 0 ? array_sum($slice) / count($slice) : 0.0;
    }

    private function ema(array $values, int $period): float
    {
        $n = count($values);
        if ($n === 0) return 0.0;

        $k = 2 / ($period + 1);
        $ema = $values[0];

        for ($i = 1; $i < $n; $i++) {
            $ema = ($values[$i] * $k) + ($ema * (1 - $k));
        }

        return $ema;
    }

    private function categorize(array $values): array
    {
        return array_map(function ($v) {
            if ($v < 2.0) return 'low';
            if ($v < 5.0) return 'mid';
            return 'high';
        }, $values);
    }

    private function clamp(float $value): float
    {
        return max($this->minPred, min($this->maxPred, $value));
    }

    private function clampConf(float $value): float
    {
        return max(0.10, min(0.95, $value));
    }

    private function randomInRange(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }
}
