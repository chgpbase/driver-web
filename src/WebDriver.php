<?php

namespace BotMan\Drivers\Web;

use BotMan\BotMan\Drivers\Events\GenericEvent;
use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Interfaces\WebAccess;
use BotMan\BotMan\Messages\Attachments\Audio;
use BotMan\BotMan\Messages\Attachments\File;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Attachments\Video;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\BotMan\Messages\Outgoing\Question;
use BotMan\BotMan\Users\User;
use BotMan\Drivers\Web\Extras\AttachmentVisitorReply;
use BotMan\Drivers\Web\Extras\TypingIndicator;
use BotTemplateFramework\Distinct\Web\Extensions\ButtonTemplate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
//use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Pusher\Pusher;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebDriver extends HttpDriver
{
    const DRIVER_NAME = 'Web';

    const ATTACHMENT_IMAGE = 'image';
    const ATTACHMENT_AUDIO = 'audio';
    const ATTACHMENT_VIDEO = 'video';
    const ATTACHMENT_FILE = 'file';
    const ATTACHMENT_LOCATION = 'location';

    /** @var OutgoingMessage[] */
    protected $replies = [];

    /** @var int */
    protected $replyStatusCode = 200;

    /** @var string */
    protected $errorMessage = '';

    /** @var string */

    protected $channel = '';

    /** @var array */
    protected $messages = [];

    /** @var array */
    protected $files = [];

    /**
     * @param  Request  $request
     */
    public function buildPayload(Request $request)
    {
        $this->payload = $request->request->all();
        $this->event = Collection::make($this->payload);
        $this->files = Collection::make($request->files->all());
        $this->config = Collection::make($this->config->get('web', []));
    }

    /**
     * @param  IncomingMessage  $matchingMessage
     * @return \BotMan\BotMan\Users\User
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        return new User($matchingMessage->getSender(), user_info: ['location'=>$this->event->get('location')]);
//        Log::info(print_r($user, true));
//        return $user;
       // return new User($matchingMessage->getSender(), user_info: ['location'=>$this->event->get('location')]);
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        return Collection::make($this->config->get('matchingData'))->diffAssoc($this->event)->isEmpty();
    }

    /**
     * @param  IncomingMessage  $matchingMessage
     * @return void
     */
    public function types(IncomingMessage $matchingMessage)
    {
        $this->replies[] = [
            'message' => TypingIndicator::create(),
            'additionalParameters' => [],
        ];
    }

    /**
     * Send a typing indicator and wait for the given amount of seconds.
     *
     * @param  IncomingMessage  $matchingMessage
     * @param  float  $seconds
     * @return mixed
     */
    public function typesAndWaits(IncomingMessage $matchingMessage, float $seconds)
    {
        $this->replies[] = [
            'message' => TypingIndicator::create($seconds),
            'additionalParameters' => [],
        ];
    }

    /**
     * @param  IncomingMessage  $message
     * @return \BotMan\BotMan\Messages\Incoming\Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        $interactive = $this->event->get('interactive', false);
        if (is_string($interactive)) {
            $interactive = ($interactive !== 'false') && ($interactive !== '0');
        } else {
            $interactive = (bool) $interactive;
        }

        return Answer::create($message->getText())
            ->setValue($this->event->get('value', $message->getText()))
            ->setMessage($message)
            ->setInteractiveReply($interactive);
    }

    /**
     * @return bool
     */
    public function hasMatchingEvent()
    {
        $event = false;

        if ($this->event->has('eventData')) {
            $event = new GenericEvent($this->event->get('eventData'));
            $event->setName($this->event->get('eventName'));
        }

        return $event;
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {
        if (empty($this->messages)) {
            $message = $this->event->get('message');
            $userId = $this->event->get('userId');
            $sender = $this->event->get('sender', $userId);

            $incomingMessage = new IncomingMessage($message, $sender, $userId, $this->payload);

            $incomingMessage = $this->addAttachments($incomingMessage);

            $this->messages = [$incomingMessage];
            $this->sendPayload([
                'message' => $incomingMessage,
                'additionalParameters' => null,
                'recipient'=> $sender
            ]);
        }

        return $this->messages;
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        return false;
    }

    /**
     * @param  string|Question|OutgoingMessage  $message
     * @param  IncomingMessage  $matchingMessage
     * @param  array  $additionalParameters
     * @return Response
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        if (! $message instanceof WebAccess && ! $message instanceof OutgoingMessage) {
            $this->errorMessage = 'Unsupported message type.';
            $this->replyStatusCode = 500;
        }

        $recipient = $matchingMessage->getRecipient() === '' ? $matchingMessage->getSender() : $matchingMessage->getRecipient();


        return [
            'message' => $message,
            'additionalParameters' => $additionalParameters,
            'recipient'=> $recipient
        ];
    }

    /**
     * @param  mixed  $payload
     * @return Response
     */
    public function sendPayload($payload)
    {
        if($this->matchesRequest())
            $this->replies[] = $payload;
        else {
            $pusher= new Pusher(config('broadcasting.connections')['pusher']['key'],
                config('broadcasting.connections')['pusher']['secret'],
                config('broadcasting.connections')['pusher']['app_id'],
                config('broadcasting.connections')['pusher']['options']);
            $reply=$this->buildReply([$payload])[0];
            try {
                $pusher->getChannelInfo($this->channel);
                $pusher->trigger($this->channel,'chat_message',$reply);
            } catch (\Exception){
                $unread=\Cache::get('unread-'.$this->channel, []);
                $unread[]=$reply;
                \Cache::put('unread-'.$this->channel, $unread, Carbon::now()->addDays(90));
            }


        }
    }

    /**
     * @param $messages
     * @return array
     */
    protected function buildReply($messages)
    {
        $replyData = Collection::make($messages)->transform(function ($replyData) {
            $reply = [];
            $message = $replyData['message'];
            if(empty($this->channel)) $this->channel='chat_'.$replyData['recipient'];
            $additionalParameters = $replyData['additionalParameters'];
            if (is_array($message)) {
                $reply = $message;
            } elseif ($message instanceof WebAccess) {
                $reply = $message->toWebDriver();
            } elseif($message instanceof IncomingMessage) {
                $attachmentData=array_merge($message->getAudio(), $message->getFiles(), $message->getImages());//, $payload->getContact(), $payload->getLocation());
                if(count($attachmentData)==1) {
                    $reply=(new AttachmentVisitorReply('', $attachmentData[0]))->toWebDriver();
                } else $reply=[
                    'type' => 'text',
                    'text' =>$message->getText(),
                    'from' =>'visitor'
                ];
            }  elseif ($message instanceof OutgoingMessage) {
                $attachmentData = (is_null($message->getAttachment())) ? null : $message->getAttachment()->toWebDriver();
                $reply = [
                    'type' => 'text',
                    'text' => $message->getText(),
                    'attachment' => $attachmentData,
                ];
            }
            $reply['additionalParameters'] = $additionalParameters;

            return $reply;
        })->toArray();

        return $replyData;
    }

    /**
     * Send out message response.
     */
    public function messagesHandled()
    {
        $messages = $this->buildReply($this->replies);

        // Reset replies
        $this->replies = [];

        if(!empty($this->channel)) {
            $pusher= new Pusher(config('broadcasting.connections')['pusher']['key'],
                config('broadcasting.connections')['pusher']['secret'],
                config('broadcasting.connections')['pusher']['app_id'],
                config('broadcasting.connections')['pusher']['options']);

            try {
                $pusher->getChannelInfo($this->channel);
                foreach ($messages as $message) {
                    $pusher->trigger($this->channel,'chat_message',$message);
                }
            } catch (\Exception){
                $unread=\Cache::get('unread-'.$this->channel, []);
                \Cache::put('unread-'.$this->channel, array_merge($unread,$messages), Carbon::now()->addDays(90));
            }

            $messages=[];
        }

        (new Response(json_encode([
            'status' => $this->replyStatusCode,
            'messages' => $messages,
        ]), $this->replyStatusCode, [
            'Content-Type' => 'application/json',
            'Access-Control-Allow-Credentials' => true,
            'Access-Control-Allow-Origin' => '*',
        ]))->send();
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return false;
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param  string  $endpoint
     * @param  array  $parameters
     * @param  \BotMan\BotMan\Messages\Incoming\IncomingMessage  $matchingMessage
     * @return void
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        // Not available with the web driver.
    }

    /**
     * Add potential attachments to the message object.
     *
     * @param  IncomingMessage  $incomingMessage
     * @return IncomingMessage
     */
    protected function addAttachments($incomingMessage)
    {
        $filetype = strtolower($this->event->get('filetype'));

        //if (strpos( $filetype, self::ATTACHMENT_IMAGE)===0) {
        if(in_array($filetype, ['image/png', 'image/jpg', 'image/bmp', 'image/webp', 'image/gif'])) {
            $images = $this->files->map(function ($file) use ($incomingMessage) {
//                if ($file instanceof UploadedFile) {
//                    $path = $file->getRealPath();
//                } else {
//                    $path = $file['tmp_name'];
//                }
                $bot_dir='bot_file_cache';
                \Intervention\Image\Facades\Image::make($file)->resize(600, 600, function ($constraint) {
                    $constraint->aspectRatio();
                })->save( 'core/storage/app/'.$bot_dir.'/'.$file->getFilename().'.png');
//                $url=url('/core/storage/app/'.$bot_dir.'/'.$file->getFilename().'.'.$file->getClientOriginalExtension());
                $url=url('/core/storage/app/'.$bot_dir.'/'.$file->getFilename().'.png');
//                Storage::put('/'.$bot_dir.'/'.$file->getFilename().'.'.$file->getClientOriginalExtension(), file_get_contents($file));
                return new Image($url);
//                return new Image($this->getDataURI($path));
            })->values()->toArray();
            $incomingMessage->setText(Image::PATTERN);
            $incomingMessage->setImages($images);
//        } elseif (strpos( $filetype, self::ATTACHMENT_AUDIO)===0) {
        } elseif (in_array($filetype, ['audio/mp3', 'audio/ogg', 'audio/wav', 'audio/mpeg', 'audio/webm'])) {
            $audio = $this->files->map(function ($file) use ($incomingMessage) {
                if ($file instanceof UploadedFile) {
                    $path = $file->getRealPath();
                } else {
                    $path = $file['tmp_name'];
                }
                $bot_dir='bot_file_cache';
                $url=url('/core/storage/app/'.$bot_dir.'/'.$file->getFilename().'.'.$file->getClientOriginalExtension());
                Storage::put('/'.$bot_dir.'/'.$file->getFilename().'.'.$file->getClientOriginalExtension(), file_get_contents($file));
                return new Audio($url);
//                return new Audio($this->getDataURI($path));
            })->values()->toArray();
            $incomingMessage->setText(Audio::PATTERN);
            $incomingMessage->setAudio($audio);
//        } elseif (strpos( $filetype, self::ATTACHMENT_VIDEO)===0) {
        } elseif (in_array($filetype, ['video/mp4', 'video/mov', 'video/avi', 'video/x-msvideo', 'video/mp4', 'video/mpeg', 'video/ogg', 'video/webm'])) {
            $videos = $this->files->map(function ($file) use ($incomingMessage) {
                if ($file instanceof UploadedFile) {
                    $path = $file->getRealPath();
                } else {
                    $path = $file['tmp_name'];
                }
                $bot_dir='bot_file_cache';
                $url=url('/core/storage/app/'.$bot_dir.'/'.$file->getFilename().'.'.$file->getClientOriginalExtension());
                Storage::put('/'.$bot_dir.'/'.$file->getFilename().'.'.$file->getClientOriginalExtension(), file_get_contents($file));
                return new Video($url);
//                return new Video($this->getDataURI($path));
            })->values()->toArray();
            $incomingMessage->setText(Video::PATTERN);
            $incomingMessage->setVideos($videos);
        } elseif (in_array($filetype, ['application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/gzip', 'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'])) {
            $files = $this->files->map(function ($file) use ($incomingMessage) {
                if ($file instanceof UploadedFile) {
                    $path = $file->getRealPath();
                } else {
                    $path = $file['tmp_name'];
                }
                $bot_dir='bot_file_cache';
                $url=url('/core/storage/app/'.$bot_dir.'/'.$file->getFilename().'.'.$file->getClientOriginalExtension());
                Storage::put('/'.$bot_dir.'/'.$file->getFilename().'.'.$file->getClientOriginalExtension(), file_get_contents($file));
                return new File($url);
//                return new File($this->getDataURI($path));
            })->values()->toArray();
            $incomingMessage->setText(File::PATTERN);
            $incomingMessage->setFiles($files);
        } elseif ($this->event->get('filename')!='') { abort(501, 'Not supported file: '.$this->event->get('filename'));}

        return $incomingMessage;
    }

    /**
     * @param $file
     * @param  string  $mime
     * @return string
     */
    protected function getDataURI($file, $mime = '')
    {
        return 'data: '.(function_exists('mime_content_type') ? mime_content_type($file) : $mime).';base64,'.base64_encode(file_get_contents($file));
    }
}
