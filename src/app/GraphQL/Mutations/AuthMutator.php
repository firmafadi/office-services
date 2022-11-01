<?php

namespace App\GraphQL\Mutations;

use App\Enums\KafkaStatusTypeEnum;
use App\Exceptions\CustomException;
use App\Http\Traits\KafkaTrait;
use App\Models\People;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;

class AuthMutator
{
    use KafkaTrait;

    /**
     * @param $rootValue
     * @param $args
     *
     * @throws \Exception
     *
     * @return array
     */
    public function login($rootValue, array $args)
    {
        if (extension_loaded('newrelic')) {
            newrelic_name_transaction("App\GraphQL\Mutations\AuthMutator@login");
        }

        /**
         * @var $people People
         */
        // TODO implement the resolver
        $username = $args['input']['username'];
        $people = People::where('PeopleUsername', $username)->first();

        //check password
        $checkPassword = false;
        if ($people) {
            if ($people->is_new_hash) {
                $checkPassword = Hash::check($args['input']['password'], $people->PeoplePassword);
            } else {
                $checkPassword = (sha1($args['input']['password']) == $people->PeoplePassword) ? true : false;
            }
        }

        if (!$people || $people->PeopleIsActive == 0 || $checkPassword == false) {
            $this->kafkaPublish('analytic_event', [
                'event' => 'login',
                'status' => KafkaStatusTypeEnum::LOGIN_INVALID_CREDENTIALS(),
                'username' => $username,
            ]);
            throw new CustomException(
                'Invalid credential',
                'Email and password are incorrect'
            );
        }


        $issuedAt = time();
        $expTime = $issuedAt + config('jwt.refresh_ttl');

        $deviceName = Arr::get($args, 'input.device', 'default');
        $deviceFcmToken = Arr::get($args, 'input.fcm_token');

        $accessToken = $people->createToken($deviceName);
        $accessToken->accessToken->update([
            'fcm_token' => $deviceFcmToken
        ]);

        $session_userdata = $this->setSessionUserdata($people);

        $this->kafkaPublish('analytic_event', [
            'event' => 'login',
            'status' => KafkaStatusTypeEnum::LOGIN_SUCCESS(),
            'session_userdata' => $session_userdata,
        ]);

        return [
            'message' => 'success',
            'access_token' => $accessToken->plainTextToken,
            'token_type' => 'bearer',
            'expires_in' => $expTime,
            'profile' => $people
        ];
    }
}
