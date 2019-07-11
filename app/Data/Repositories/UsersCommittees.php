<?php
namespace App\Data\Repositories;

use App\Data\Models\UserCommittee;
use App\Data\Repositories\Users as UsersRepository;
use App\Data\Repositories\Committees as CommitteesRepository;

class UsersCommittees extends Base
{
    /**
     * @var $model
     */
    protected $model = UserCommittee::class;

    public function userHasCommittee($user_id, $committee_id)
    {
        return !is_null(
            $this->searchByUserAndCommittee($user_id, $committee_id)
        );
    }

    public function searchByUserAndCommittee($user_id, $committee_id)
    {
        return $this->model
            ::where('user_id', $user_id)
            ->where('committee_id', $committee_id)
            ->first();
    }

    public function syncOperatorOrAdminUser($user_id)
    {
        //Add all committees to user
        $usersRepository = app(UsersRepository::class);
        $committeesRepository = app(CommitteesRepository::class);

        $user = $usersRepository->findById($user_id);
        $committees = $committeesRepository->all();

        $committeesIds = [];
        foreach ($committees as $committee) {
            $committeesIds[] = $committee->id;
        }

        $user->committees()->sync($committeesIds);
    }

    public function insertUserCommittee($user_id, $committee_id)
    {
        $newRow = new UserCommittee();
        $newRow->user_id = $user_id;
        $newRow->committee_id = $committee_id;
        $newRow->save();
    }

    public function deleteUserCommittee($user_id, $committee_id)
    {
        if ($row = $this->searchByUserAndCommittee($user_id, $committee_id)) {
            $row->delete();
        }
    }
}
