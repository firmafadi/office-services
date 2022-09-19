<?php

namespace App\Jobs;

use App\Enums\SignatureMethodTypeEnum;
use App\Enums\SignatureQueueTypeEnum;
use App\Http\Traits\SignDocumentSignatureTrait;
use App\Models\DocumentSignatureSent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessMultipleEsignDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use SignDocumentSignatureTrait;

    protected $documentSignatureSents;
    protected $passphrase;
    protected $userId;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($documentSignatureSents, $passphrase, $userId)
    {
        $this->documentSignatureSents   = $documentSignatureSents;
        $this->passphrase               = $passphrase;
        $this->userId                   = $userId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $userId                   = $this->userId;
        $passphrase               = $this->passphrase;
        $documentSignatureSents   = $this->documentSignatureSents;
        $documentSignatureSentIds = $documentSignatureSents->pluck('id');

        DocumentSignatureSent::whereIn('id', $documentSignatureSentIds)->update([
            'progress_queue' => SignatureQueueTypeEnum::WAITING()
        ]);

        $documentSignatureEsignData = [
            'userId' => $userId,
            'esignMethod' => SignatureMethodTypeEnum::MULTIFILE()
        ];

        foreach ($documentSignatureSentIds as $documentSignatureSentId) {
            $this->processSignDocumentSignature($documentSignatureSentId, $passphrase, $documentSignatureEsignData);
        }
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception)
    {
        // Send user notification of failure, etc...
    }
}
