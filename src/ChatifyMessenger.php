<?php

namespace Chatify;

use App\Models\ChMessage as Message;
use App\Models\ChFavorite as Favorite;
use App\Models\ChChannel as Channel;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;
use Exception;

class ChatifyMessenger
{
    public $pusher;

    /**
     * Get max file's upload size in MB.
     *
     * @return int
     */
    public function getMaxUploadSize()
    {
        return config('chatify.attachments.max_upload_size') * 1048576;
    }

    public function __construct()
    {
        $this->pusher = new Pusher(
            config('chatify.pusher.key'),
            config('chatify.pusher.secret'),
            config('chatify.pusher.app_id'),
            config('chatify.pusher.options'),
        );
    }
    /**
     * This method returns the allowed image extensions
     * to attach with the message.
     *
     * @return array
     */
    public function getAllowedImages()
    {
        return config('chatify.attachments.allowed_images');
    }

    /**
     * This method returns the allowed file extensions
     * to attach with the message.
     *
     * @return array
     */
    public function getAllowedFiles()
    {
        return config('chatify.attachments.allowed_files');
    }

    /**
     * Returns an array contains messenger's colors
     *
     * @return array
     */
    public function getMessengerColors()
    {
        return config('chatify.colors');
    }

    /**
     * Returns a fallback primary color.
     *
     * @return array
     */
    public function getFallbackColor()
    {
        $colors = $this->getMessengerColors();
        return count($colors) > 0 ? $colors[0] : '#000000';
    }

    /**
     * Trigger an event using Pusher
     *
     * @param string $channel
     * @param string $event
     * @param array $data
     * @return void
     */
    public function push($channel, $event, $data)
    {
        return $this->pusher->trigger($channel, $event, $data);
    }

    /**
     * Authentication for pusher
     *
     * @param User $requestUser
     * @param User $authUser
     * @param string $channelName
     * @param string $socket_id
     * @param array $data
     * @return void
     */
    public function pusherAuth($requestUser, $authUser, $channelName, $socket_id)
    {
        // Auth data
        $authData = json_encode([
            'user_id' => $authUser->id,
            'user_info' => [
                'name' => $authUser->name
            ]
        ]);
        // check if user authenticated
        if (Auth::check()) {
            if($requestUser->id == $authUser->id){
                return $this->pusher->socket_auth(
                    $channelName,
                    $socket_id,
                    $authData
                );
            }
            // if not authorized
            return response()->json(['message'=>'Unauthorized'], 401);
        }
        // if not authenticated
        return response()->json(['message'=>'Not authenticated'], 403);
    }

    /**
     * Fetch & parse message and return the message card
     * view as a response.
     *
     * @param Message $prefetchedMessage
     * @param int $id
     * @return array
     */
    public function parseMessage($prefetchedMessage = null, $id = null)
    {
        $msg = null;
        $attachment = null;
        $attachment_type = null;
        $attachment_title = null;
        if (!!$prefetchedMessage) {
            $msg = $prefetchedMessage;
        } else {
            $msg = Message::where('id', $id)->first();
            if(!$msg){
                return [];
            }
        }
        if (isset($msg->attachment)) {
            $attachmentOBJ = json_decode($msg->attachment);
            $attachment = $attachmentOBJ->new_name;
            $attachment_title = htmlentities(trim($attachmentOBJ->old_name), ENT_QUOTES, 'UTF-8');
            $ext = pathinfo($attachment, PATHINFO_EXTENSION);
            $attachment_type = in_array($ext, $this->getAllowedImages()) ? 'image' : 'file';
        }
        return [
            'id' => $msg->id,
            'from_id' => $msg->from_id,
            'to_channel_id' => $msg->to_channel_id,
            'message' => $msg->body,
            'attachment' => (object) [
                'file' => $attachment,
                'title' => $attachment_title,
                'type' => $attachment_type
            ],
            'timeAgo' => $msg->created_at->diffForHumans(),
            'created_at' => $msg->created_at->toIso8601String(),
            'isSender' => ($msg->from_id == Auth::user()->id),
            'seen' => $msg->seen,
        ];
    }

    /**
     * Return a message card with the given data.
     *
     * @param Message $data
     * @param boolean $isSender
     * @return string
     */
    public function messageCard($data, $renderDefaultCard = false)
    {
        if (!$data) {
            return '';
        }
        if($renderDefaultCard) {
            $data['isSender'] =  false;
        }
        return view('Chatify::layouts.messageCard', $data)->render();
    }

    /**
     * Default fetch messages query between a Sender and Receiver.
     *
     * @param string $channel_id
     * @return Message|\Illuminate\Database\Eloquent\Builder
     */
    public function fetchMessagesQuery($channel_id)
    {
        return Message::where('to_channel_id', $channel_id);
    }

    /**
     * create a new message to database
     *
     * @param array $data
     * @return Message
     */
    public function newMessage($data)
    {
        $message = new Message();
        $message->from_id = $data['from_id'];
        $message->to_channel_id = $data['to_channel_id'];
        $message->body = $data['body'];
        $message->attachment = $data['attachment'];
        $message->save();
        return $message;
    }

    /**
     * Make messages between the sender [Auth user] and
     * the receiver [User id] as seen.
     *
     * @param int $user_id
     * @return bool
     */
    public function makeSeen($user_id)
    {
        Message::Where('from_id', $user_id)
                ->where('to_channel_id', Auth::user()->id)
                ->where('seen', 0)
                ->update(['seen' => 1]);
        return 1;
    }

    /**
     * Get last message for a specific user
     *
     * @param string $channel_id
     * @return Message|Collection|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|object|null
     */
    public function getLastMessageQuery($channel_id)
    {
        return $this->fetchMessagesQuery($channel_id)->latest()->first();
    }

    /**
     * Count Unseen messages
     *
     * @param int $user_id
     * @return Collection
     */
	// TODO: unseen is wrong
    public function countUnseenMessages($user_id)
    {
        return Message::where('from_id', $user_id)->where('to_channel_id', Auth::user()->id)->where('seen', 0)->count();
    }

    /**
     * Get user list's item data [Contact Itme]
     * (e.g. User data, Last message, Unseen Counter...)
     *
     * @param int $messenger_id
     * @param Collection $channel
     * @return string
     */
    public function getContactItem($channel)
    {
        try {
			$user = $this->getUserInOneChannel($channel->id);
            $lastMessage = $this->getLastMessageQuery($channel->id);
			
            // Get Unseen messages counter
//            $unseenCounter = $this->countUnseenMessages($channel->id);
	        // TODO: fix this
	        $unseenCounter = 1;
			
            if ($lastMessage) {
                $lastMessage->created_at = $lastMessage->created_at->toIso8601String();
                $lastMessage->timeAgo = $lastMessage->created_at->diffForHumans();
            }
            return view('Chatify::layouts.listItem', [
                'get' => 'users',
                'user' => $this->getUserWithAvatar($user),
                'lastMessage' => $lastMessage,
                'unseenCounter' => $unseenCounter,
                ])->render();
        } catch (\Throwable $th) {
            throw new Exception($th->getMessage());
        }
    }

    /**
     * Get user with avatar (formatted).
     *
     * @param Collection $user
     * @return Collection
     */
    public function getUserWithAvatar($user)
    {
        if ($user->avatar == 'avatar.png' && config('chatify.gravatar.enabled')) {
            $imageSize = config('chatify.gravatar.image_size');
            $imageset = config('chatify.gravatar.imageset');
            $user->avatar = 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($user->email))) . '?s=' . $imageSize . '&d=' . $imageset;
        } else {
            $user->avatar = self::getUserAvatarUrl($user->avatar);
        }
        return $user;
    }
	
	/**
	 * Get user in on channel
	 *
	 * @param int $user_id
	 * @return string
	 */
	public function getOrCreateChannel(int $user_id)
	{
		$channel_user = DB::table('chatify_channel_user')
			->select('chatify_channel_user.channel_id', DB::raw('count(chatify_channel_user.user_id) as count_user'))
			->whereIn('chatify_channel_user.user_id', [$user_id, Auth::user()->id])
			->groupBy('chatify_channel_user.channel_id')
			->having('count_user', '=', 2)
			->first();
		
		if(!isset($channel_user)){
			$new_channel = new Channel();
			$new_channel->save();
			
			$new_channel->users()->sync([$user_id, Auth::user()->id]);
			
			return $new_channel->id;
		}
		
		return $channel_user->channel_id;
	}
	
	/**
	 * Get user in on channel
	 *
	 * @param string $channel_id
	 * @return Collection
	 */
	public function getUserInOneChannel(string $channel_id)
	{
		return User::where('id', '!=', Auth::user()->id)
			->join('chatify_channel_user', 'users.id', '=', 'chatify_channel_user.user_id')
			->where('chatify_channel_user.channel_id', $channel_id)
			->select('users.*')
			->first();
	}

    /**
     * Check if a user in the favorite list
     *
     * @param string $channel_id
     * @return boolean
     */
    public function inFavorite($channel_id)
    {
        return Favorite::where('user_id', Auth::user()->id)
                        ->where('favorite_id', $channel_id)->count() > 0;
    }

    /**
     * Make user in favorite list
     *
     * @param string $channel_id
     * @param int $star
     * @return boolean
     */
    public function makeInFavorite($channel_id, $action)
    {
        if ($action > 0) {
            // Star
            $star = new Favorite();
            $star->user_id = Auth::user()->id;
            $star->favorite_id = $channel_id;
            $star->save();
            return $star ? true : false;
        } else {
            // UnStar
            $star = Favorite::where('user_id', Auth::user()->id)->where('favorite_id', $channel_id)->delete();
            return $star ? true : false;
        }
    }

    /**
     * Get shared photos of the conversation
     *
     * @param string $channel_id
     * @return array
     */
    public function getSharedPhotos($channel_id)
    {
        $images = array(); // Default
        // Get messages
        $msgs = $this->fetchMessagesQuery($channel_id)->orderBy('created_at', 'DESC');
        if ($msgs->count() > 0) {
            foreach ($msgs->get() as $msg) {
                // If message has attachment
                if ($msg->attachment) {
                    $attachment = json_decode($msg->attachment);
                    // determine the type of the attachment
                    in_array(pathinfo($attachment->new_name, PATHINFO_EXTENSION), $this->getAllowedImages())
                    ? array_push($images, $attachment->new_name) : '';
                }
            }
        }
        return $images;
    }

    /**
     * Delete Conversation
     *
     * @param string $channel_id
     * @return boolean
     */
    public function deleteConversation($channel_id)
    {
        try {
            foreach ($this->fetchMessagesQuery($channel_id)->get() as $msg) {
                // delete file attached if exist
                if (isset($msg->attachment)) {
                    $path = config('chatify.attachments.folder').'/'.json_decode($msg->attachment)->new_name;
                    if (self::storage()->exists($path)) {
                        self::storage()->delete($path);
                    }
                }
                // delete from database
                $msg->delete();
            }
            return 1;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Delete message by ID
     *
     * @param int $id
     * @return boolean
     */
    public function deleteMessage($id)
    {
        try {
            $msg = Message::where('from_id', auth()->id())->where('id', $id)->firstOrFail();
            if (isset($msg->attachment)) {
                $path = config('chatify.attachments.folder') . '/' . json_decode($msg->attachment)->new_name;
                if (self::storage()->exists($path)) {
                    self::storage()->delete($path);
                }
            }
            $msg->delete();
            return 1;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Return a storage instance with disk name specified in the config.
     *
     */
    public function storage()
    {
        return Storage::disk(config('chatify.storage_disk_name'));
    }

    /**
     * Get user avatar url.
     *
     * @param string $user_avatar_name
     * @return string
     */
    public function getUserAvatarUrl($user_avatar_name)
    {
        return self::storage()->url(config('chatify.user_avatar.folder') . '/' . $user_avatar_name);
    }

    /**
     * Get attachment's url.
     *
     * @param string $attachment_name
     * @return string
     */
    public function getAttachmentUrl($attachment_name)
    {
        return self::storage()->url(config('chatify.attachments.folder') . '/' . $attachment_name);
    }
}
