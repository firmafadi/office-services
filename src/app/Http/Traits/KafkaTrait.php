<?php

namespace App\Http\Traits;

use Illuminate\Support\Facades\Log;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\Message;

/**
 * Producing Kafka messages
 */
trait KafkaTrait
{
    /**
     * Publishing Kafka mesasges
     *
     * @param String $topic
     * @param Array $data
     *
     * @return Void
     */
    public function kafkaPublish($topic, $data)
    {
        $enabled = config('kafka.enable');
        if (!$enabled) {
            return false;
        }

        $data['medium']     = 'mobile';
        $data['timestamp']  = time();
        if (auth()->user()) {
            $data['session_userdata'] = auth()->user();
        }

        $message = new Message(body: $data);
        /** @var \Junges\Kafka\Producers\ProducerBuilder $producer */
        $producer = Kafka::publishOn($topic)
            ->withConfigOptions(['compression.type' => 'none'])
            ->withMessage($message);

        Log::info('Start publish messages to Kafka.');
        $producer->send();
        Log::info('Finish publish messages to Kafka.');
    }
}
