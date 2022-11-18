<?php

namespace App\Http\Traits;

use App\Enums\MediumTypeEnum;
use Carbon\Carbon;
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
     * @param Array $header
     *
     * @return Void
     */
    public function kafkaPublish($topic, $data, $header = null)
    {
        $enabled = config('kafka.enable');
        if (!$enabled) {
            return false;
        }

        $data['medium']     = MediumTypeEnum::MOBILE();
        $data['timestamp']  = Carbon::now()->toIso8601ZuluString();

        if ($header == null) {
            $header = getallheaders();
            unset($header['Authorization']);
            $data['header'] = $header;
        } else {
            $data['header'] = $header;
        }

        if (auth()->check()) {
            $user = auth()->user();
            $data['session_userdata'] = $this->setSessionUserdata($user);
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

    /**
     * setSessionUserdata
     *
     * Set session userdata like SIDEBAR WEBSITE
     *
     * @param  mixed $user
     * @return void
     */
    public function setSessionUserdata($user)
    {
        $session_userdata['peopleid']       = $user->PeopleId;
        $session_userdata['groupid']        = $user->GroupId;
        $session_userdata['groupname']      = $user->group?->GroupName;
        $session_userdata['peopleusername'] = $user->PeopleUsername;
        $session_userdata['peoplename']     = $user->PeopleName;
        $session_userdata['peopleposition'] = $user->PeoplePosition;
        $session_userdata['roleid']         = $user->role?->RoleId;
        $session_userdata['namabagian']     = $user->role?->RoleDesc;
        $session_userdata['gjabatanid']     = $user->role?->gjabatanId;
        $session_userdata['roleatasan']     = $user->RoleAtasan;
        $session_userdata['rolecode']       = $user->role?->RoleCode;
        $session_userdata['rolecode_name']  = $user->role?->roleCode?->rolecode_name;
        $session_userdata['rolecode_sort']  = $user->role?->roleCode?->rolecode_sort;
        $session_userdata['nik']            = $user->NIK;
        $session_userdata['groleid']        = $user->role?->GRoleId;
        $session_userdata['code_tu']        = $user->role?->Code_Tu;
        $session_userdata['approvelname']   = $user->ApprovelName;
        $session_userdata['primaryroleid']  = $user->PrimaryRoleId;

        return $session_userdata;
    }
}
