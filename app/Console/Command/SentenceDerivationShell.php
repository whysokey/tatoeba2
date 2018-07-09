<?php

class Walker {
    private $model;
    private $buffer = array();
    public $bufferSize = 1000;
    public $allowRewindSize = 20;

    public function __construct($model) {
        $this->model = $model;
    }

    private function setBufferPointerAt($i) {
        reset($this->buffer);
        while (key($this->buffer) !== $i) {
            next($this->buffer);
        }
    }

    public function next() {
        $next = next($this->buffer);
        if ($next === false) {
            if (empty($this->buffer)) {
                $lastId = 0;
            } else {
                $last = end($this->buffer);
                $lastId = $last[$this->model->alias][$this->model->primaryKey];
            }
            $fetchSize = $this->bufferSize - $this->allowRewindSize;
            $rows = $this->model->find('all', array(
                'conditions' => array('id > ' => $lastId),
                'limit' => $fetchSize,
            ));
            if (empty($rows)) {
                return false;
            }
            $remainder = array_slice($this->buffer, -$this->allowRewindSize, $this->allowRewindSize);
            $this->buffer = array_merge($remainder, $rows);
            $this->setBufferPointerAt(count($remainder));
            $next = current($this->buffer);
        }
        return $next;
    }

    public function findAround($range, $matchFunction) {
        return array_merge(
            $this->findBefore($range, $matchFunction),
            $this->findAfter($range, $matchFunction)
        );
    }

    public function findAfter($range, $matchFunction) {
        $matches = array();
        $max = $range;
        for ($i = 0; $i < $max; $i++) {
           $row = $this->next($this->buffer);
           if ($row === false) {
              $range--;
           } else {
               if ($matchFunction($row)) {
                   $matches[] = $row;
               }
           }
        }
        if ($range != $max) {
           reset($this->buffer);
        }
        for ($i = 0; $i < $max; $i++) {
           prev($this->buffer);
        }
        return $matches;
    }

    public function findBefore($range, $matchFunction) {
        $matches = array();
        $max = $range;
        for ($i = 0; $i < $max; $i++) {
           if (prev($this->buffer) === false) {
              $range--;
           }
        }
        if ($range != $max) {
           reset($this->buffer);
        }
        for ($i = 0; $i < $range; $i++) {
           $row = current($this->buffer);
           if ($matchFunction($row)) {
               $matches[] = $row;
           }
           $this->next($this->buffer);
        }
        return $matches;
    }
}

class SentenceDerivationShell extends AppShell {

    public $uses = array('Sentence', 'Contribution');

    public function main() {
        $proceeded = $this->setSentenceBasedOnId();
        $this->out("\n$proceeded sentences proceeded.\n");
    }

    public function setSentenceBasedOnId() {
        $derivations = array();
        $saveExtraOptions = array(
            'modified' => false,
            'callbacks' => false
        );
        $walker = new Walker($this->Contribution);
        while ($log = $walker->next()) {
            $log = $log['Contribution'];
            if ($log['action']   == 'insert' &&
                $log['type']     == 'sentence')
            {
                $sentenceId = $log['sentence_id'];
                $matches = $walker->findAround(3, function ($elem) use ($log) {
                    $creatDate = strtotime($log['datetime']);
                    $otherDate = strtotime($elem['Contribution']['datetime']);
                    return abs($otherDate - $creatDate) <= 1;
                });
                $basedOnId = -1;
                if (count($matches) == 0) {
                    $basedOnId = null;
                } elseif (count($matches) == 2) {
                    foreach ($matches as $match) {
                        $match = $match['Contribution'];
                        if ($match['sentence_id'] == $sentenceId &&
                            $match['translation_id'] != null) {
                            $basedOnId = $match['translation_id'];
                        }
                    }
                }
                if ($basedOnId != -1) {
                    $update = array('id' => $sentenceId, 'based_on_id' => $basedOnId);
                    $derivations[] = array_merge($update, $saveExtraOptions);
                }
            }
        }
        $this->Sentence->saveAll($derivations);
    }
}
