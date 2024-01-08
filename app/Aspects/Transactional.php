<?php

namespace App\Aspects;
use App\Http\Controllers\FileController;
use AhmadVoid\SimpleAOP\Aspect;
use Illuminate\Support\Facades\Cache;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Transactional implements Aspect
{

    // The constructor can accept parameters for the attribute
    public function __construct(
        public $expiration = 10,
        public $maxAttemptingTime = 5,
        public $key = null 
    ){

    }
    

    public function executeBefore($request,$FileController,$reserveFiles)
    {
        //Get or generating a unique key
        $lockKey = $this->key ?? get_class($FileController) . '_' . $reserveFiles;
    
        //Cache::lock($resourceKey, $seconds)

        $lock = Cache::lock($lockKey,$this->expiration);

        $lock->block($this->maxAttemptingTime);
        $request->attributes->set('lock' , $lock);

    }

    public function executeAfter($request,$FileController,$reserveFiles,$response)
    {
       $lock = $request->attributes->get('lock');
       $lock->release();
    }

    public function executeException($request,$FileController,$reserveFiles,$exception)
    {
        $lock = $request->attributes->get('lock');
        $lock?->release();
    }
}
