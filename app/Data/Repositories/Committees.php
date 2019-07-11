<?php

namespace App\Data\Repositories;

use App\Data\Models\ViaModel;
use App\Data\Models\Committee;

class Committees extends Base
{
    /**
     * @var $model
     */
    protected $model = Committee::class;

    public function getCommitteeCombobox($id = null)
    {
        if (is_null($id)) {
            return $this->allWhereOperator('bio', '<>', '');
        } else {
            return $this->findById($id);
        }
    }

    public function findBySlug($slug)
    {
        return Committee::where('slug', $slug)->first();
    }

    public function comboBoxItemsWithScope()
    {
        return $this->model
            ::permittedCommittees()
            ->where('bio', '<>', '')
            ->get();
    }

    public function searchByEverything($search)
    {
        return ($result = $this->searchByAll($search))->count() == 0
            ? $this->emptyResponse()
            : $this->response($result);
    }

    public function searchByName($name)
    {
        return $this->model::where('name', 'ilike', '%' . $name . '%')->get();
    }

    public function searchByAll($name)
    {
        return $this->model
            ::orWhere('name', 'ilike', '%' . $name . '%')
            ->orWhere('president', 'ilike', '%' . $name . '%')
            ->orWhere('vice_president', 'ilike', '%' . $name . '%')
            ->get();
    }

    public function searchPresident($name)
    {
        return $this->model
            ::where('president', 'ilike', '%' . $name . '%')
            ->get();
    }

    public function searchVicePresident($name)
    {
        return $this->model
            ::where('vice_president', 'ilike', '%' . $name . '%')
            ->get();
    }

    protected function emptyResponse($search = '')
    {
        return $this->response($search, 0, null);
    }

    protected function response($data, $count = 0, $messages = null)
    {
        return [
            'data' => $data,
            'success' => is_null($messages),
            'errors' => $messages,
            'count' => $count,
        ];
    }
}
