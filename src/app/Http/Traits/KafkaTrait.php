<?php

namespace App\Http\Traits;

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
        $data['medium']     = 'mobile';
        $data['timestamp']  = time();
        if (auth()->user()) {
            $data['session_userdata'] = auth()->user();
        }

        $message = new Message(body: $data);
        /** @var \Junges\Kafka\Producers\ProducerBuilder $producer */
        $producer = Kafka::publishOn($topic)->withMessage($message);
        $producer->send();
    }
}
