<?php
namespace App\Data\Models;

use App\Data\Repositories\ContactTypes;
use App\Support\Constants;
use App\Data\Presenters\Person as PersonPresenter;

class Person extends BaseModel
{
    /**
     * @var array
     */
    protected $fillable = [
        'cpf_cnpj',
        'name',
        'identification',
        'is_anonymous',
        'civil_status_id',
        'spouse_name',
        'ocupacao_principal_id',
        'scholarship_id',
        'income',
        'person_type_id',
        'created_by_id',
        'updated_by_id',
    ];

    protected $presenters = ['created_at_formatted', 'updated_at_formatted'];

    public function getPresenterClass()
    {
        return PersonPresenter::class;
    }

    protected $flushKeys = [Constants::CACHE_KEY_PEOPLE_SEARCH_BY_EVERYTHING];

    public function contacts()
    {
        return $this->hasMany(PersonContact::class);
    }

    public function addresses()
    {
        return $this->hasMany(PersonAddress::class);
    }

    public function records()
    {
        return $this->hasMany(Record::class);
    }

    public function emails()
    {
        if ($this->name == 'Anônimo') {
            return collect();
        }

        $type = app(ContactTypes::class)->findByName('E-mail');

        return $this->contacts()->where('contact_type_id', $type->id);
    }

    /**
     * Add webhook url for slack.
     *
     * @return \Illuminate\Config\Repository|mixed
     */
    public function routeNotificationForSlack()
    {
        return config('services.slack.webhook_url');
    }

    public function findOrCreateAddress($data)
    {
        if (
            $address = $this->addresses()
                ->where('zipcode', only_numbers($data['zipcode']))
                ->first()
        ) {
            return $address;
        }

        return $this->addresses()->create([
            'zipcode' => only_numbers($data['cep']),
            'street' => $data['rua'],
            'number' => $data['numero'],
            'complement' => $data['complemento'],
            'neighbourhood' => $data['bairro'],
            'city' => $data['cidade'],
            'state' => $data['state'],
            'is_mailable' => true,
            'validated_at' => dow(),
            'active' => true,
        ]);
    }

    public function findOrCreatePhone($data)
    {
        if (
            $contact = $this->contacts()
                ->where('contact', ($contact = only_numbers($data['contact'])))
                ->first()
        ) {
            return $contact;
        }

        return $this->addresses()->create([
            'contact_type_id' => app(ContactTypes::class)->findByName('Celular')
                ->id,
            'contact' => $contact,
            'from' => 'personal',
            'active' => true,
        ]);
    }
}
