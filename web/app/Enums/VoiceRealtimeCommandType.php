<?php

namespace App\Enums;

enum VoiceRealtimeCommandType: string
{
    case SessionUpdate = 'session.update';
    case ResponseCreate = 'response.create';
    case ConversationItemCreate = 'conversation.item.create';
    case ResponseCancel = 'response.cancel';
    case OutputAudioBufferClear = 'output_audio_buffer.clear';
}
