<?php

namespace App\Enums;

enum TeamPermission: string
{
    case UpdateTeam = 'team:update';
    case DeleteTeam = 'team:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    case CreateCourse = 'course:create';
    case UpdateCourse = 'course:update';
    case PublishCourse = 'course:publish';
    case DeleteCourse = 'course:delete';
}
