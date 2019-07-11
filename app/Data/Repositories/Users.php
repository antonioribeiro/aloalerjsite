<?php

namespace App\Data\Repositories;

use App\Data\Models\UserType;
use App\Data\Models\User;
use App\Services\Authorization;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

use App\Data\Repositories\Committees as CommitteesRepository;
use App\Data\Repositories\UsersCommittees as UsersCommitteesRepository;
use App\Data\Repositories\UserTypes as UserTypesRepository;

class Users extends Base
{
    /**
     * @var string
     */
    protected $model = User::class;

    protected $tiposUsuarios;

    /**
     * @var Authorization
     */
    private $authorization;

    /**
     * Users constructor.
     *
     * @param Authorization $authorization
     */
    public function __construct(
        Authorization $authorization,
        UserTypes $tiposUsuarios
    ) {
        $this->authorization = $authorization;

        $this->tiposUsuarios = $tiposUsuarios;
    }

    /**
     * @param $request
     *
     * @return mixed
     */
    private function credentials($request)
    {
        return $request->only(['username', 'password']);
    }

    /**
     * @param $email
     *
     * @return mixed
     */
    public function findUserByEmail($email)
    {
        return User::where('email', $email)->first();
    }

    /**
     * @param $id
     *
     * @return mixed
     */
    public function findUserById($id)
    {
        return User::find($id);
    }

    /**
     * @param $username
     *
     * @return mixed
     */
    private function getUserType($username)
    {
        return // $this->authorization->getUserProfiles($username)->first()
            UserType::where('name', 'Usuario')->first();
    }

    private function getUserTypeFromPermissions($permissions)
    {
        $other = '';
        $admin = false;
        $committeeBool = false;

        $userTypesRepository = app(UserTypesRepository::class);
        $userTypesArray = $userTypesRepository->toArrayWithColumnKey(
            $userTypesRepository->all(),
            'name'
        );

        foreach ($permissions as $permission) {
            if (isset($userTypesArray[$permission['nomeFuncao']])) {
                if ($permission['nomeFuncao'] == 'Administrador') {
                    $admin = true;
                } else {
                    $other = $permission['nomeFuncao'];
                }
            }
        }

        if ($other == '' && !$admin) {
            $committeesRepository = app(CommitteesRepository::class);
            $allCommittees = $committeesRepository->getCommitteeCombobox();

            $eventsArray = [];
            foreach ($permissions as $permission) {
                $eventsArray[$permission['evento']] = true;
            }

            foreach ($allCommittees as $committee) {
                if (isset($eventsArray[$committee->slug])) {
                    //O usuário tem permissão para a comissão $committee no SGUS
                    $committeeBool = true;
                }
            }
        }

        if ($admin) {
            return app(UserTypes::class)->findByName('Administrador');
        } elseif ($other != '') {
            return app(UserTypes::class)->findByName($other);
        } elseif ($committeeBool) {
            return app(UserTypes::class)->findByName('Comissao');
        } else {
            return null;
        }
    }

    private function isAdministrador($permissions)
    {
        return $this->isType($permissions, 'Administrador');
    }

    /**
     * @param $permissions
     * @param $type
     *
     * @return bool
     */
    private function isType($permissions, $type)
    {
        return $permissions
            ->filter(function ($user) use ($type) {
                return starts_with($user['nomeFuncao'], $type);
            })
            ->count() > 0;
    }

    /**
     * @param $request
     * @param $remember
     *
     * @return bool
     */
    public function loginUser($request, $remember)
    {
        try {
            $credentials = extract_credentials($request);

            if (
                is_null(
                    ($user = $this->findUserByEmail(
                        ($email = "{$credentials['username']}@alerj.rj.gov.br")
                    ))
                )
            ) {
                $user = new User();

                $user->name = $credentials['username'];

                $user->username = $credentials['username'];

                $user->email = $email;

                $user->password = Hash::make($credentials['password']);

                $userType = $this->getUserTypeFromPermissions(
                    app(Authorization::class)->getUserPermissions(
                        $user->username
                    )
                );

                if (is_null($userType)) {
                    return false;
                } else {
                    $user->user_type_id = $userType->id;
                }

                $user->save();
            } else {
                $user->password = Hash::make($credentials['password']);
                $user->save();
            }

            Auth::login($user, $remember);
        } catch (\Exception $exception) {
            return false;
        }

        return true;
    }

    /**
     * @param $type
     *
     * @return mixed
     */
    public function getByType($type)
    {
        $type = $this->tiposUsuarios->findByName($type);

        $model = $this->model;

        return $this->makeResultForSelect(
            $model::where('user_type_id', $type->id)->get(),
            'name'
        );
    }

    /**
     * @return mixed
     */
    public function all()
    {
        $query = User::orderBy('name');

        if (!is_administrator()) {
            $administrator = $this->tiposUsuarios->findByName('Administrador');

            $query->where('user_type_id', '!=', $administrator->id);
        }

        return $query->get();
    }

    public function notifiables()
    {
        return User::where('all_notifications', true)->get();
    }

    private function updateCurrentCommitteesViaPermissions($permissions)
    {
        $returnCommitteesPermissions = [];

        $user = Auth::user();

        $eventsArray = [];

        foreach ($permissions as $permission) {
            $eventsArray[$permission['evento']] = true;
        }

        $committeesRepository = app(CommitteesRepository::class);
        $userCommitteesRepository = app(UsersCommitteesRepository::class);
        $allCommittees = $committeesRepository->getCommitteeCombobox();

        foreach ($allCommittees as $committee) {
            if (isset($eventsArray[$committee->slug])) {
                //O usuário tem permissão para a comissão $committee no SGUS
                $returnCommitteesPermissions[] = $committee;
                if (
                    !$userCommitteesRepository->userHasCommittee(
                        $user->id,
                        $committee->id
                    )
                ) {
                    //precisa inserir
                    $userCommitteesRepository->insertUserCommittee(
                        $user->id,
                        $committee->id
                    );
                }
            } else {
                //O usuário não tem permissão para a comissão $committee no SGUS
                if (
                    $userCommitteesRepository->userHasCommittee(
                        $user->id,
                        $committee->id
                    )
                ) {
                    //precisa deletar
                    $userCommitteesRepository->deleteUserCommittee(
                        $user->id,
                        $committee->id
                    );
                }
            }
        }

        //Retorn committees permissions array
        return $returnCommitteesPermissions;
    }

    public function updateCurrentUserTypeViaPermissions($permissions)
    {
        $user = Auth::user();
        $usersCommitteesRepository = app(UsersCommitteesRepository::class);

        $userTypesRepository = app(UserTypesRepository::class);
        $userTypesArray = $userTypesRepository->toArrayWithColumnKey(
            $userTypesRepository->all(),
            'name'
        );

        $administrator = false;
        $userType = null;

        foreach ($permissions as $permission) {
            if ($permission['nomeFuncao'] == 'Administrador') {
                $userType = $userTypesArray['Administrador'];
                $administrator = true;
                $usersCommitteesRepository->syncOperatorOrAdminUser(
                    Auth::user()->id
                );
            }
        }

        if (!$administrator) {
            if (
                !empty(
                    $this->updateCurrentCommitteesViaPermissions($permissions)
                )
            ) {
                $userType = $userTypesArray['Comissao'];
            } else {
                foreach ($permissions as $permission) {
                    if (isset($userTypesArray[$permission['nomeFuncao']])) {
                        $userType = $userTypesArray[$permission['nomeFuncao']];
                    }
                }
                if ($userType) {
                    $usersCommitteesRepository->syncOperatorOrAdminUser(
                        Auth::user()->id
                    );
                }
            }
        }

        if ($userType) {
            $user->user_type_id = $userType->id;
            $user->save();
        } else {
            dd('Você não está autorizado a usar o sistema');
        }
    }
}
