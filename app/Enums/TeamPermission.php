<?php

namespace App\Enums;

enum TeamPermission: string
{
    case UpdateTeam = 'team:update';
    case DeleteTeam = 'team:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';
    case ManageMemberStatus = 'member:status';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    case CreateCourse = 'course:create';
    case UpdateCourse = 'course:update';
    case AssignCourse = 'course:assign';
    case PublishCourse = 'course:publish';
    case DeleteCourse = 'course:delete';

    case CreateExam = 'exam:create';
    case UpdateExam = 'exam:update';
    case PublishExam = 'exam:publish';
    case DeleteExam = 'exam:delete';
    case ReviewExam = 'exam:review';

    case CreateRoadmap = 'roadmap:create';
    case UpdateRoadmap = 'roadmap:update';
    case PublishRoadmap = 'roadmap:publish';
    case AssignRoadmap = 'roadmap:assign';
    case DeleteRoadmap = 'roadmap:delete';
}
