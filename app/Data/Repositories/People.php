<?php

namespace App\Data\Repositories;

use App\Data\Models\Person;

class People extends Base
{
    const RECORDS_COUNT_LIMIT = 20;

    /**
     * @var $model
     */
    protected $model = Person::class;

    private function addExtraInfo($people)
    {
        return $people;
        return $people->map(function ($person) {
            $person->records = $person->records->map(function ($record) {
                $record->protocol_formatted = $record->presenter()->protocol_formatted;
            });

            return $person;
        });
    }

    private function emptyResponse($search = '')
    {
        return $this->response($search, [], 0, null);
    }

    protected function error($count, $messages)
    {
        return $this->response(null, null, $count, $messages);
    }

    protected function getBaseQuery()
    {
        return $this->model
            ::with(['contacts', 'addresses', 'records'])
            ->take(static::RECORDS_COUNT_LIMIT + 1);
    }

    private function isNumeric($string)
    {
        return is_numeric(remove_punctuation($string));
    }

    protected function response($string, $data, $count = 0, $messages = null)
    {
        return [
            'data' => $data,
            'success' => is_null($messages),
            'errors' => $messages,
            'count' => $count,
            'is_cpf_cnpj' => validate_cpf_cnpj($string),
            'is_numeric' => $this->isNumeric($string),
        ];
    }

    protected function searchByProtocolNumber($string)
    {
        $record = app(Records::class)->findByProtocol($string);

        if ($record) {
            $query = $this->model
                ::with(['contacts', 'addresses'])
                ->with([
                    'records' => function ($query) use ($string) {
                        $query->where('protocol', '=', $string);
                    },
                ])
                ->take(static::RECORDS_COUNT_LIMIT + 1)
                ->where('id', $record->person_id);

            return $this->response($string, $query->get(), $query->count());
        }

        return $this->emptyResponse();
    }

    public function findByCpfCnpj($cpfCnpj)
    {
        return $this->getBaseQuery()
            ->where('cpf_cnpj', only_numbers($cpfCnpj))
            ->first();
    }

    protected function searchByCpf($string)
    {
        if (!validate_cpf_cnpj($string)) {
            return $this->emptyResponse($string);
        }

        $query = $this->getBaseQuery()->where(
            'cpf_cnpj',
            only_numbers($string)
        );

        return $this->response(
            $string,
            $this->addExtraInfo($query->get()),
            $query->count()
        );
    }

    protected function searchByName($string)
    {
        $query = $this->getBaseQuery()->whereRaw(
            "unaccent(name) ILIKE '%'||unaccent('" .
                pg_escape_string($string) .
                "')||'%' "
        );

        if ($query->count() > static::RECORDS_COUNT_LIMIT) {
            return $this->error(
                $query->count(),
                'Busca resultou em mais de 20 registros'
            );
        }

        return $this->response($string, $query->get(), $query->count());
    }

    public function searchByEverything($search)
    {
        if (empty(trim(($search = $search['search'])))) {
            return $this->emptyResponse();
        }

        if ($this->isNumeric($search)) {
            $search = remove_punctuation($search);

            $response = $this->searchByCpf($search);

            if ($response['success'] && $response['count'] > 0) {
                return $response;
            }

            $protocol = $this->searchByProtocolNumber($search);

            $response['errors'] = $response['errors'] ?: $protocol['errors'];

            if (!is_null($protocol['data'])) {
                $response['data'] = coollect($response['data'])->merge(
                    $protocol['data']
                );
            }

            return $response;
        }

        return $this->searchByName($search);
    }

    public function create($data)
    {
        return Person::create($data);
    }
}
