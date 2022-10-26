<?php

namespace App\Http\Traits;

use App\Enums\DocumentSignatureSentNotificationTypeEnum;
use App\Enums\FcmNotificationActionTypeEnum;
use App\Enums\FcmNotificationListTypeEnum;
use App\Models\DocumentSignatureSent;
use App\Models\InboxReceiver;
use App\Models\InboxReceiverCorrection;
use Illuminate\Support\Facades\Http;

/**
 * Send notification to mobile device using firebase cloud messaging
 */
trait SendNotificationTrait
{
    public function setupInboxReceiverNotification($request)
    {
        $action = $request['data']['action'];
        switch ($action) {
            case FcmNotificationActionTypeEnum::DRAFT_DETAIL():
            case FcmNotificationActionTypeEnum::DRAFT_REVIEW():
                $inboxReceiverModel = InboxReceiverCorrection::whereIn('To_Id', $request['data']['peopleIds']);
                break;

            default:
                $inboxReceiverModel = InboxReceiver::whereIn('To_Id', $request['data']['peopleIds']);
                break;
        }
        $inboxReceiver = $inboxReceiverModel->where('NId', $request['data']['inboxId'])
                                    ->where('GIR_Id', $request['data']['groupId'])
                                    ->with('personalAccessTokens')
                                    ->get();
        if (!$inboxReceiver) {
            return false;
        }

        foreach ($inboxReceiver as $message) {
            $lastToken        = $message->personalAccessTokens->pluck('fcm_token')->last();
            $token            = [$lastToken];
            $messageAttribute = $this->setNotificationAttribute($token, $request, $message, $action);
            $this->sendNotification($messageAttribute);
        }

        return true;
    }

    /**
     * setupDocumentSignatureSentNotification
     *
     * @param  mixed $request
     * @param  string $fcmToken
     * @return boolean
     */
    public function setupDocumentSignatureSentNotification($request, $fcmToken = null)
    {
        list($data, $token) = $this->setDocumentSignatureSentTarget($request);

        $token = ($fcmToken != null) ? [$fcmToken] : $token;

        if (!$data) {
            return false;
        }

        $messageAttribute = $this->setNotificationAttribute(
            $token,
            $request,
            $data,
            FcmNotificationActionTypeEnum::DOC_SIGNATURE_DETAIL()
        );

        $send = $this->sendNotification($messageAttribute);

        return true;
    }

    /**
     * setDocumentSignatureSentTarget
     *
     * @param  object $request
     * @return array
     */
    public function setDocumentSignatureSentTarget($request)
    {
        $documentSignatureSent = DocumentSignatureSent::where('id', $request['data']['documentSignatureSentId']);
        if ($request['data']['target'] == DocumentSignatureSentNotificationTypeEnum::SENDER()) {
            $documentSignatureSent->with('senderPersonalAccessTokens');
        }

        if ($request['data']['target'] == DocumentSignatureSentNotificationTypeEnum::RECEIVER()) {
            $documentSignatureSent->with('receiverPersonalAccessTokens');
        }

        $data = $documentSignatureSent->first();

        if (!$data) {
            return [false, false];
        }

        if ($request['data']['target'] == DocumentSignatureSentNotificationTypeEnum::SENDER()) {
            $lastToken = $data->senderPersonalAccessTokens->pluck('fcm_token')->last();
        }

        if ($request['data']['target'] == DocumentSignatureSentNotificationTypeEnum::RECEIVER()) {
            $lastToken = $data->receiverPersonalAccessTokens->pluck('fcm_token')->last();
        }

        $token = [$lastToken];

        return [$data, $token];
    }

    /**
     * setNotificationAttribute
     *
     * @param  array $token
     * @param  array $request
     * @param  object $record
     * @param  enum $action
     * @return array
     */
    public function setNotificationAttribute($token, $request, $record, $action)
    {
        $messageAttribute = [
            'registration_ids' => $token,
            'notification' => $request['notification'],
            'data' => $request['data']
        ];

        $messageAttribute['data']['id'] = $record->id;

        if (
            $action == FcmNotificationActionTypeEnum::DRAFT_DETAIL() ||
            $action == FcmNotificationActionTypeEnum::DRAFT_REVIEW()
        ) {
            $messageAttribute['data'] = [
                'draftId' => $record->NId,
                'groupId' => $record->GIR_Id,
                'receiverAs' => $record->ReceiverAs,
                'letterNumber' => optional($record->draftDetail)->nosurat,
                'draftStatus' => optional($record->draftDetail)->Konsep,
                'action' => $action,
                'list' => FcmNotificationListTypeEnum::DRAFT_INSIDE(),
            ];
        }

        return $messageAttribute;
    }

    /**
     * sendNotification
     *
     * @param  mixed $request
     * @return object
     */
    public function sendNotification($request)
    {
        $SERVER_API_KEY = config('fcm.server_key');

        $data = [
            'registration_ids' => $request['registration_ids'],
            'notification' => $request['notification'],
            'data' => $request['data']
        ];

        $response = Http::withHeaders([
            'Authorization' => 'key=' . $SERVER_API_KEY,
            'Content-Type' => 'application/json',
        ])->post(config('fcm.url'), $data);

        return json_decode($response->body());
    }
}
