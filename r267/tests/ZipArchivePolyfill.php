<?php
declare(strict_types=1);
/** QA-only portable fallback, not shipped in product ZIP. */
if (!class_exists('ZipArchive')) {
    class ZipArchive {
        public int $numFiles=0;
        private string $path='';
        private array $names=[];
        public function open(string $p): bool {
            if(!is_file($p))return false;
            $cmd='unzip -Z1 '.escapeshellarg($p).' 2>/dev/null';
            $raw=shell_exec($cmd); if(!is_string($raw)||$raw==='')return false;
            $this->path=$p;$this->names=explode("\n",trim($raw));$this->numFiles=count($this->names);return true;
        }
        public function getNameIndex(int $i): string|false {return $this->names[$i]??false;}
        public function locateName(string $name): int|false {$i=array_search($name,$this->names,true);return $i===false?false:$i;}
        public function getFromName(string $name): string|false {
            if($this->locateName($name)===false)return false;
            $cmd='unzip -p '.escapeshellarg($this->path).' '.escapeshellarg($name).' 2>/dev/null';
            $bytes=shell_exec($cmd);return is_string($bytes)?$bytes:false;
        }
        public function getStream(string $name) {
            $bytes=$this->getFromName($name);if(!is_string($bytes))return false;
            $s=fopen('php://temp','w+b');fwrite($s,$bytes);rewind($s);return $s;
        }
        public function close(): bool {$this->path='';$this->names=[];$this->numFiles=0;return true;}
    }
}
