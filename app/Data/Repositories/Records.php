<?php
namespace App\Data\Repositories;

use Carbon\Carbon;
use App\Data\Models\Record;
use Illuminate\Support\Facades\Auth;
use App\Data\Repositories\People as PeopleRepository;

class Records extends Base
{
    /**
     * @var $model
     */
    protected $model = Record::class;

    protected $peopleRepository;

    /**
     * Records constructor.
     *
     * @param PeopleRepository $personRepository
     * @internal param Repository $repository
     */
    public function __construct(PeopleRepository $personRepository)
    {
        $this->peopleRepository = $personRepository;
    }

    /**
     * @param $person
     * @param $record
     */
    private function addProtocolNumberToRecord($person, $record): void
    {
        if (!$record->protocol) {
            $record->protocol = $this->makeProtocolNumber($person, $record);
            $record->save();
        }
    }

    /**
     * @param person_id
     *
     * @return mixed
     */
    public function findByPerson($person_id)
    {
        return $this->model::where('person_id', $person_id)->get();
    }

    public function create($data)
    {
        $person = $this->peopleRepository->findById($data->person_id);

        if (isset($data->record_id)) {
            $data = $data->merge(['id' => $data->record_id]);
        }

        $record = $this->createFromRequest($data);

        $this->addProtocolNumberToRecord($person, $record);

        return $record;
    }

    private function makePersonalDataInfoFromContactData($data)
    {
        return "Data de nascimento: {$data['birthdate']}\n" .
            "Sexo: {$data['sex_1']}\n" .
            "Identidade de gênero: {$data['sex_2']}\n" .
            "Escolaridade: {$data['scholarship']}\n" .
            "Área de atuação: {$data['area']}\n";
    }

    public function markAsResolved($record_id, $progress = null)
    {
        $record = $this->model::find($record_id);
        $record->resolved_at = now();
        $record->resolve_progress_id = $progress->id;
        $record->resolved_by_id = Auth::user()->id;
        $record->save();
    }

    public function markAsNotResolved($record_id)
    {
        $record = $this->model::find($record_id);
        $record->resolved_at = null;
        $record->resolve_progress_id = null;
        $record->resolved_by_id = null;
        $record->save();
    }

    public function findByProtocol($protocol)
    {
        return app(Records::class)->findByColumn(
            'protocol',
            $this->cleanProtocol($protocol)
        );
    }

    private function cleanProtocol($protocol)
    {
        return preg_replace('/[^0-9]/', '', $protocol);
    }

    public function allNotResolved()
    {
        return $this->model
            ::whereNull('resolved_at')
            ->orderBy('created_at', 'desc')
            ->paginate(15);
    }

    /**
     * @param $person
     * @param $record
     * @return string
     */
    public function makeProtocolNumber($person, $record)
    {
        return sprintf(
            '%s%s%s%s',
            Carbon::now()->format('Ymd'),
            str_pad(trim($person->id), 8, '0', STR_PAD_LEFT),
            Carbon::now()->format('Hi'),
            str_pad(trim($record->id), 8, '0', STR_PAD_LEFT)
        );
    }

    /**
     * @param $data
     */
    public function absorbContactForm($data)
    {
        $person = $this->peopleRepository->findByCpfCnpj($data['cpf']);

        if (!$person) {
            $person = $this->peopleRepository->create(
                ($data = [
                    'cpf_cnpj' => $data['cpf'],
                    'name' => $data['name'],
                    'identification' => trim(
                        $data['identidade'] . ' ' . $data['expeditor']
                    ),
                ])
            );
        }

        if (!$person->notes) {
            $person->notes = $this->makePersonalDataInfoFromContactData($data);

            $person->save();
        }

        $person->findOrCreateAddress([
            'person_id' => $person->id,
            'zipcode' => $data['cep'],
            'street' => $data['rua'],
            'number' => $data['numero'],
            'complement' => $data['complemento'],
            'neighbourhood' => $data['bairro'],
            'city' => $data['cidade'],
            'state' => 'RJ',
            'is_mailable' => true,
            'validated_at' => now(),
            'active' => true,
        ]);

        $person->findOrCreatePhone([
            'person_id' => $person->id,
            'contact' => $data['telephone'],
        ]);

        $record = $this->create(
            coollect([
                'committee_id' => app(Committees::class)->findByName(
                    'ALÔ ALERJ'
                )->id,
                'person_id' => $person->id,
                'record_type_id' => app(RecordTypes::class)->findByName(
                    'Outros'
                )->id,
                'area_id' => ($areaId = app(Areas::class)->findByName(
                    'ALÔ ALERJ'
                )->id),
                'record_action_id' => app(RecordActions::class)->findByName(
                    'Outros'
                )->id,
            ])
        );

        $progress = app(Progresses::class)->create([
            'record_id' => $record->id,
            'progress_type_id' => app(ProgressTypes::class)->findByName('Email')
                ->id,
            'original' => "Assunto: {$data['subject']}\n\n{$data['message']}",
            'origin_id' => app(Origins::class)->findByName('E-mail')->id,
            'area_id' => $areaId,
            'area_id',
            'objeto_id',
            'record_action_id',
        ]);

        $record->sendNotifications();

        $progress->sendNotifications();
    }
}
