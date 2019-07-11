<?php
namespace App\Services;

use App\Data\Models\Area;
use App\Data\Models\Committee;
use App\Data\Models\Origin;
use App\Data\Models\RecordAction;
use App\Data\Models\RecordType;
use App\Data\Repositories\Records;
use Carbon\Carbon;
use App\Data\Models\User;
use App\Data\Models\Record;
use App\Data\Models\Person;
use App\Data\Models\Progress;
use App\Data\Models\UserType;
use App\Data\Models\ContactType;
use App\Data\Models\ProgressType;
use App\Data\Models\PersonAddress;
use App\Data\Models\PersonContact;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Debug\Exception\FatalThrowableError;

class ImportCercred
{
    protected $command;

    protected $counters = [];

    private function allHistoryIdsExceptForPerson(
        $person_id,
        $firstHistory,
        $lastHistory
    ) {
        $exclude = array_filter([$firstHistory, $lastHistory]);

        if (count($exclude) == 0) {
            return collect();
        }

        return coollect(
            $this->db()->select(
                "select
      historico_id
        from historico
        where imported = false and historico.pessoa_id = {$person_id} and historico.historico_id not in (" .
                    implode(',', $exclude) .
                    ');'
            )
        );
    }

    private function getAllHistory($historico_id)
    {
        $history = coollect(
            $this->db()->select(
                'select
  historico_propriedade.historico_id,
  historico_propriedade.historico_propriedade_id,
  historico_propriedade.valor historico_propriedade_valor,
  historico_propriedade_tipo.historico_propriedade_tipo,
  historico_propriedade_tipo.descricao historico_propriedade_tipo_descricao
from historico
  left join historico_propriedade on historico_propriedade.historico_id = historico.historico_id
  left join historico_propriedade_tipo on historico_propriedade_tipo.historico_propriedade_tipo = historico_propriedade.historico_propriedade_tipo
  left join historico_tipo on historico.historico_tipo = historico_tipo.historico_tipo
  left join action_historico on historico_tipo.historico_tipo = action_historico.historico_tipo
  left join action on action.action_id = action_historico.action_id
  left join action_type on action_type.action_type = action.action_type
where  historico_propriedade_tipo.descricao is not null
and historico.historico_id = ' . $historico_id
            )
        );

        if ($history->isEmpty()) {
            return null;
        }

        return $history;
    }

    public function import($command)
    {
        ini_set('memory_limit', '2048M');

        $this->command = $command;

        \Debugbar::disable();

        DB::connection()->disableQueryLog();

        //        $this->people();
        //        //
        //        $this->emails();
        //        //
        //        $this->phones();
        //        //
        //        $this->addresses();
        //        //
        //        $this->users();
        //        //
        //        $this->progressTypes();
        //
        //        $this->recordActions();

        $this->records();

        $this->firstAndLastHistories();

        //        $this->inferAndFillMissinData();
    }

    private function inferAndFillMissinData()
    {
        Record::all()->each(function ($record) {
            $history = $record->progresses
                ->where('original_history_id', $record->historico_id)
                ->first();

            if ($history) {
                $history = json_decode(json_encode($history->toArray()));

                $action = $this->findActionByHistoryId(
                    $history->original_history_id
                );

                $history->history_data[] = coollect([
                    'history_fields' => coollect(
                        json_decode($history->history_fields, true)
                    ),
                ])->merge(json_decode(json_encode($action, true)));

                $record->area_id =
                    $this->inferAreaFromProtocol($history) ?: 999999;

                $record->record_type_id = $this->inferRecordTypeFromProtocol(
                    $history
                );

                $record->record_action_id = $this->inferActionFromProtocol(
                    $history
                );

                $historico = coollect(
                    $this->db()->select(
                        "select * from historico where historico_id = {$history->original_history_id}"
                    )
                )->first();

                $record->created_at =
                    $historico->data_inicio_atendimento ?: $record->created_at;

                $record->save();

                $this->increment('INFER MISSING');
            }
        });
    }

    private function personDoesNotHaveAnyOtherProtocols($person_id)
    {
        return is_null(Record::where('person_id', $person_id)->first());
    }

    public function updateProgressTypes($command)
    {
        $this->command = $command;

        $this->info('Updating PROGRESS TYPES...');

        $elements = [
            'Demanda de atendimento',
            'Telefone',
            'Email',
            'Outras',
            'Prestação de serviços',
            'Água e Esgoto',
            'Lixo',
            'Projetos de Lei',
            'Denúncia',
            'Agradecimento/Elogio',
            'Demanda sem clareza',
            'Outros',
            'Pedido',
            'Luz ou Iluminação',
            'Transportes',
            'Saúde',
            'Educação',
            'Serviços Públicos',
            'Serviços Privados',
            'ALERJ',
            'Falta de fiscalização',
            'Outros',
            'Segurança Pública',
            'Maus tratos aos animais',
            'Comissão do Trabalho',
            'Queda de Ligação',
            'Reenvio de protocolo',
            'Comissão dos Direitos da Mulher',
            'Venda',
            'Não Venda',
            'Não Tabulado',
            'Exclusão da Venda',
            'Pré-Venda',
            'Expirado',
            'E-mail Material de Venda Enviado',
            'SMS Enviado',
            'Voice Messenger Enviado',
            'Carta Enviada',
            'Resposta de Protocolo',
            'Fidelização',
            'Agendamento Automático',
            'Informação',
            'Baixa de CPF',
            'Baixa de Contrato',
            'Baixa de Contrato Parcela',
            'Devolução de CPF',
            'Devolução de Contrato',
            'Devolução de Contrato Parcela',
            'Discador - Não Atende',
            'Discador - Ocupado',
            'Discador - Máquina',
            'Discador - Fax',
            'Discador - Sem Agente Disp.',
            'Discador - Número Errado',
            'Discador - Cliente Desligou',
            'Discador - Mudo',
            'Discador - Validado',
            'Discador - Recado URA',
            'Discador - Não Retornar',
            'Discador - Desconhece Pessoa',
            'Discador - Caixa Postal',
            'Discador - Mensagem Operadora',
            'Discador - Silêncio',
            'Discador - Falha na Operadora',
            'Discador - Congestionamento',
        ];

        ProgressType::truncate();

        foreach ($elements as $key => $element) {
            $this->info('Inserting ' . $element);
            ProgressType::insert([
                'id' => $key,
                'name' => $element,
            ]);
        }
    }

    private function createProgress($history, $record)
    {
        if ($history->historico_complemento) {
            return Progress::create(
                $this->sanitize([
                    'original_history_id' => $history->historico_id,
                    'record_id' => $record->id,
                    'progress_type_id' => ProgressType::firstOrCreate([
                        'name' => $history->historico_tipo_descricao,
                    ])->id,
                    'created_by_id' => $history->historico_usuario_id_alteracao,
                    'original' => $history->historico_complemento,
                    'created_at' => $history->historico_data_inicio_atendimento,
                    'updated_at' => $history->historico_data_inicio_atendimento,
                    'history_fields' => $history->history_fields->toJson(),
                    'origin_id' => $this->inferOriginFromHistory($history),
                ])
            );
        }

        return null;
    }

    private function createRecordFromProtocol($protocol)
    {
        return Record::create(
            $this->sanitize([
                'protocol' => $protocol->protocolo_codigo,
                'person_id' => $protocol->pessoa_id,
                'objeto_id' => $protocol->objeto_id,
                'historico_id' => $protocol->historico_id,
                'historico_id_finalizador' =>
                    $protocol->historico_id_finalizador,
                'person_first_record' => $this->personDoesNotHaveAnyOtherProtocols(
                    $protocol->pessoa_id
                ),
                'record_type_id' => $protocol->pessoa_id,
                'area_id' => $this->inferAreaFromProtocol($protocol) ?: 999999,
                'record_action_id' => $this->inferActionFromProtocol($protocol),
                'created_at' => ($date = $this->inferDateFromProtocol(
                    $protocol
                )),
                'updated_at' => $date,
            ])
        );
    }

    /**
     * @param $history
     * @return mixed
     */
    private function findRecordActionByName($history)
    {
        $action = RecordAction::where(
            'name',
            $history->action_description
        )->first();

        return $action;
    }

    /**
     * @param $history
     * @return mixed
     */
    private function findRecordTypeByName($history)
    {
        $action = RecordType::where(
            'name',
            $history->action_description
        )->first();

        return $action;
    }

    public function inferRecordTypeFromProtocol($protocol)
    {
        $type = null;

        if (isset($protocol->history_data[0])) {
            $history = $protocol->history_data[0];

            $type = $this->findRecordTypeByName($history);

            if (!$type && $history->action_id) {
                RecordType::insert([
                    'id' => $history->action_id,
                    'name' => $history->action_description,
                ]);

                $type = $this->findRecordTypeByName($history);

                DB::statement(
                    "SELECT setval('public.record_types_id_seq', (SELECT max(id) FROM public.record_types));"
                );
            }
        }

        return $type ? $type->id : null;
    }

    public function inferActionFromProtocol($protocol)
    {
        $action = null;

        if (isset($protocol->history_data[0])) {
            $history = $protocol->history_data[0];

            $action = $this->findRecordActionByName($history);

            if (!$action && $history->action_id) {
                RecordAction::insert([
                    'id' => $history->action_id,
                    'name' => $history->action_description,
                ]);

                $action = $this->findRecordActionByName($history);

                DB::statement(
                    "SELECT setval('public.record_actions_id_seq', (SELECT max(id) FROM public.record_actions));"
                );
            }
        }

        return $action ? $action->id : null;
    }

    private function inferDateFromProtocol($protocol)
    {
        if (isset($protocol->history_data[0])) {
            return $protocol->history_data[0]
                ->historico_data_inicio_atendimento;
        }

        return null;
    }

    private function inferAreaFromProtocol($protocol)
    {
        if (isset($protocol->history_data[0])) {
            $data = coollect($protocol->history_data[0]->history_fields)
                ->where(
                    'historico_propriedade_tipo_descricao',
                    'Comissão Responsável'
                )
                ->first();

            if (
                $data instanceof \stdClass ||
                isset($data['historico_propriedade_valor'])
            ) {
                return Area::firstOrCreate([
                    'name' => $data->historico_propriedade_valor,
                ])->id;
            }
        }

        return null;
    }

    private function inferOriginFromHistory($history)
    {
        if (!($history = $this->getAllHistory($history->historico_id))) {
            return null;
        }

        $origin = $history
            ->where('historico_propriedade_tipo_descricao', 'Origem')
            ->first();

        if (
            $origin instanceof \stdClass ||
            isset($origin['historico_propriedade_valor'])
        ) {
            return Origin::firstOrCreate([
                'name' => $origin->historico_propriedade_valor,
            ])->id;
        }

        return null;
    }

    private function inferOriginFromProtocol($protocol)
    {
        if (isset($protocol->history_data[0])) {
            $data = $protocol->history_data[0]->history_fields
                ->where('historico_propriedade_tipo_descricao', 'Origem')
                ->first();

            if (
                $data instanceof \stdClass ||
                isset($data['historico_propriedade_valor'])
            ) {
                return Origin::firstOrCreate([
                    'name' => $data->historico_propriedade_valor,
                ])->id;
            }
        }

        return null;
    }

    protected function firstAndLastHistories()
    {
        $this->info('Importing FIRST-HISTORY...');

        //Progress::truncate();
        //$this->db()->statement('update historico set imported = false');

        //        \DB::listen(function ($query) {
        //            $this->info($query->sql);
        //            $this->info(json_encode($query->bindings));
        //        });

        Record::all()->each(function ($record) {
            $this->increment(
                'RECORD FOR HISTORY',
                100,
                "record {$record->id} - person {$record->person_id}"
            );

            $ids = [];

            $firstHistory = !empty($record->historico_id)
                ? $record->historico_id
                : null;

            $lastHistory = !empty($record->historico_id_finalizador)
                ? $record->historico_id_finalizador
                : null;

            if ($firstHistory) {
                $ids[] = $record->historico_id;
            }

            if ($record->person_first_record) {
                $this->allHistoryIdsExceptForPerson(
                    $record->person_id,
                    $firstHistory,
                    $lastHistory
                )->each(function ($history) use (&$ids) {
                    $ids[] = $history->historico_id;
                });
            }

            if ($lastHistory) {
                $ids[] = $lastHistory;
            }

            if (count($ids) == 0) {
                return;
            }

            $allHistory = $this->getHistory($ids, 'historico_id');

            $allHistory->each(function ($history) use ($record, $allHistory) {
                if ($history->historico_id) {
                    $history->history_fields = $this->getHistoryFields(
                        $history->historico_id
                    );

                    $this->increment(
                        'PROGRESS',
                        100,
                        "objeto {$record->objeto_id} historico {$record->historico_id} - history count {$allHistory->count()} - record {$record->id} - person {$record->person_id}"
                    );

                    $this->db()->statement(
                        'update historico set imported = true where historico_id = ' .
                            $history->historico_id .
                            ';'
                    );

                    return $this->createProgress($history, $record);
                }
            });

            unset($allHistory);
            unset($ids);
        });
    }

    public function importHistory($history, $record)
    {
        $history->history_fields = $this->getHistoryFields(
            $history->historico_id
        );

        $this->db()->statement(
            'update historico set imported = true where historico_id = ' .
                $history->historico_id .
                ';'
        );

        $progress = $this->createProgress($history, $record);
        if ($progress && $progress->created_at < $record->created_at) {
            $record->updated_at = $record->created_at = $progress->created_at;
            $record->save();
        }
        return $progress;
    }

    protected function records()
    {
        $this->info('Deleting old records...');
        DB::statement(
            'update cercred.historico set imported = false where imported = true'
        );

        $this->info('Deleting old records...');
        DB::table('public.records')
            ->whereDate('created_at', '<=', '2018-08-27')
            ->orWhereDate('created_at', '>', '2018-09-12')
            ->orWhereNull('created_at')
            ->delete();

        $this->info('Deleting old progresses...');
        DB::table('public.progresses')
            ->whereDate('created_at', '<=', '2018-08-27')
            ->orWhereDate('created_at', '>', '2018-09-12')
            ->orWhereNull('created_at')
            ->delete();

        $this->info('Importing RECORDS...');

        Person::all()->each(function ($person) {
            $personProtocols = $this->getProtocolsForPerson($person);

            $personProtocols->each(function ($protocol) use (
                $personProtocols,
                $person
            ) {
                $record = $this->importProtocol($protocol);

                //PEGAR OS PRIMEIROS DA TABELA PROTOCOLO DA CERCRED
                $history =
                    $personProtocols->count() > 1
                        ? $this->getHistoryFromProtocol([
                            $record->historico_id,
                            $record->historico_id_finalizador,
                        ])
                        : $this->getHistory($person->id, 'pessoa_id');

                $history->each(function ($history) use ($record) {
                    $this->importHistory($history, $record);
                });

                $this->increment(
                    'RECORDS',
                    100,
                    "{$protocol->pessoa_nome} ({$protocol->pessoa_id}) - record"
                );
            });

            if ($personProtocols->count() > 1) {
                $record = false;

                $this->getHistory($person->id, 'pessoa_id')->each(function (
                    $history
                ) use ($record, $person) {
                    if (!$record) {
                        $record = $this->createFixRecord($person);
                    }

                    $this->importHistory($history, $record);
                });
            }

            $this->checkMemory();
        });

        //        $this->getHistory($protocol->objeto_id)->each(function ($history) use (
        //            $newProtocol,
        //            $protocol
        //        ) {
        //            $history->history_fields = $this->getHistoryFields(
        //                $history->historico_id
        //            );
        //            $this->createProgress($history, $newProtocol);
        //            $this->increment(
        //                10,
        //                "{$protocol->pessoa_nome} ({$protocol->pessoa_id})"
        //            );
        //        });
    }

    public function createFixRecord($person)
    {
        $record = Record::create(
            $this->sanitize([
                'person_id' => $person->id,
                'record_type_id' => RecordType::where('name', 'Outros')->first()
                    ->id,
                'area_id' => Area::where('name', 'ALÔ ALERJ')->first()->id,
                'committee_id' => Committee::where('name', 'ALÔ ALERJ')->first()
                    ->id,
            ])
        );
        $record->protocol = app(Records::class)->makeProtocolNumber(
            $person,
            $record
        );
        $record->save();
        return $record;
    }
    public function createProgressFromHistory($history, $newProtocol, $protocol)
    {
        $history->history_fields = $this->getHistoryFields(
            $history->historico_id
        );
        $this->createProgress($history, $newProtocol);
        $this->increment(
            'HISTORY',
            10,
            "{$protocol->pessoa_nome} ({$protocol->pessoa_id})"
        );
    }
    public function getHistoryFromProtocol($ids)
    {
        $ids = collect((array) $ids)
            ->reject(function ($value) {
                return empty($value);
            })
            ->toArray();

        return $this->getHistory($ids, 'historico_id');
    }
    /**
     * @param $this
     */
    function checkMemory()
    {
        if (memory_get_peak_usage() > 1500450656) {
            $this->info('----------------------- END OF MEMORY');
            die();
        }
    }

    public function importProtocol($protocol)
    {
        try {
            return $this->createRecordFromProtocol($protocol);
        } catch (\Exception $exception) {
            report($exception);

            throw $exception;
        } catch (FatalThrowableError $exception) {
            report($exception);

            throw $exception;
        }
    }

    private function recordActions()
    {
        $this->info('Importing RECORD ACTIONS...');

        RecordAction::truncate();

        $this->db()
            ->table('action')
            ->get()
            ->each(function ($row) {
                RecordAction::insert([
                    'id' => $row->action_id,
                    'name' => $row->description,
                ]);
            });

        DB::statement(
            "SELECT setval('public.record_actions_id_seq', (SELECT max(id) FROM public.record_actions));"
        );
    }

    protected function progressTypes()
    {
        $this->info('Importing PROGRESS TYPES...');

        ProgressType::truncate();

        $this->db()
            ->table('historico_tipo')
            ->get()
            ->each(function ($row) {
                ProgressType::insert([
                    'id' => $row->historico_tipo,
                    'name' => $row->descricao,
                ]);
            });

        DB::statement(
            "SELECT setval('public.progress_types_id_seq', (SELECT max(id) FROM public.progress_types));"
        );
    }

    protected function users()
    {
        $this->info('Importing USERS...');

        User::truncate();

        $this->db()
            ->table('usuario')
            ->get()
            ->each(function ($row) {
                User::insert(
                    $this->sanitize([
                        'id' => $row->usuario_id,
                        'name' => $row->nome,
                        'email' => $row->nome . '@cercred.com.br',
                        'username' => $row->nome,
                        'user_type_id' => UserType::where(
                            'name',
                            'Usuario'
                        )->first()->id,
                        'password' => bcrypt($row->nome . $row->usuario_id),
                    ])
                );
            });
    }

    protected function addresses()
    {
        $this->counter = 0;

        if (
            PersonAddress::count() ==
            $this->db()
                ->table('endereco')
                ->count()
        ) {
            $this->info('ADDRESSES: done');

            return;
        }

        $this->info('Importing ADDRESSES...');

        PersonAddress::truncate();

        $statuses = coollect(
            $this->db()
                ->table('endereco_status')
                ->get()
        );

        $types = coollect(
            $this->db()
                ->table('endereco_tipo')
                ->get()
        );

        $this->db()
            ->table('endereco')
            ->get()
            ->each(function ($endereco) use ($statuses, $types) {
                $type = lower(
                    $types
                        ->where('endereco_tipo', $endereco->endereco_tipo)
                        ->first()->descricao
                );

                $status = lower(
                    $statuses
                        ->where('endereco_status', $endereco->endereco_status)
                        ->first()->descricao
                );

                PersonAddress::create(
                    $this->sanitize(
                        $this->sanitize([
                            'person_id' => $endereco->pessoa_id,
                            'zipcode' => $endereco->cep,
                            'street' => $endereco->endereco,
                            'complement' => $endereco->complemento,
                            'neighbourhood' => $endereco->bairro,
                            'city' => $endereco->cidade,
                            'state' => $endereco->uf,
                            'is_mailable' => true,
                            'from' => $type,
                            'status' => $status,
                            'address_id' => $endereco->endereco_id,
                        ])
                    )
                );

                $this->increment('ADDRESSES', 100, $endereco->endereco);
            });

        DB::statement(
            "SELECT setval('public.person_addresses_id_seq', (SELECT max(id) FROM public.person_addresses));"
        );
    }

    protected function phones()
    {
        $this->counter = 0;

        $phoneId = ContactType::where('code', 'phone')->first()->id;
        $mobileId = ContactType::where('code', 'mobile')->first()->id;

        if (
            PersonContact::whereIn('contact_type_id', [
                $phoneId,
                $mobileId,
            ])->count() ==
            $this->db()
                ->table('telefone')
                ->count()
        ) {
            $this->info('PHONES: done');

            return;
        }

        $this->info('Importing PHONES...');

        PersonContact::whereIn('contact_type_id', [
            $phoneId,
            $mobileId,
        ])->delete();

        $statuses = $this->db()
            ->table('telefone_status')
            ->get();

        $types = $this->db()
            ->table('telefone_tipo')
            ->get();

        $this->db()
            ->table('telefone')
            ->get()
            ->each(function ($telefone) use (
                $phoneId,
                $mobileId,
                $statuses,
                $types
            ) {
                $type = lower(
                    coollect($types)
                        ->where('telefone_tipo', $telefone->telefone_tipo)
                        ->first()->descricao
                );

                $status = lower(
                    coollect($statuses)
                        ->where('telefone_status', $telefone->telefone_status)
                        ->first()->descricao
                );

                PersonContact::create(
                    $this->sanitize([
                        'person_id' => $telefone->pessoa_id,
                        'contact_type_id' =>
                            $type == 'celular' ? $mobileId : $phoneId,
                        'contact' => $telefone->ddd . $telefone->telefone,
                        'from' => $type == 'celular' ? 'pessoal' : $type,
                        'status' => $status,
                        'provider_enrichment_id' =>
                            $telefone->enriquecimento_provedor_id,
                        'telefone_id' => $telefone->telefone_id,
                        'created_at' => Carbon::parse($telefone->inclusao),
                    ])
                );

                $this->increment('PHONES', 100, $telefone->telefone);
            });

        DB::statement(
            "SELECT setval('public.person_contacts_id_seq', (SELECT max(id) FROM public.person_contacts));"
        );
    }

    protected function emails()
    {
        $this->counter = 0;

        $contactTypeId = ContactType::where('code', 'email')->first()->id;

        if (
            PersonContact::where('contact_type_id', $contactTypeId)->count() ==
            $this->db()
                ->table('email')
                ->count()
        ) {
            $this->info('EMAILS: done');

            return;
        }

        $this->info('Importing EMAILS...');

        PersonContact::where('contact_type_id', $contactTypeId)->delete();

        $statuses = $this->db()
            ->table('email_status')
            ->get();

        $types = $this->db()
            ->table('email_tipo')
            ->get();

        //->where('email', 'sandrinhabs_35@yahoo.com.br')
        $this->db()
            ->table('email')
            ->get()
            ->each(function ($email) use ($contactTypeId, $statuses, $types) {
                $type = coollect($types)
                    ->where('email_tipo', $email->email_tipo)
                    ->first()->descricao;

                $status = coollect($statuses)
                    ->where('email_status', $email->email_status)
                    ->first()->descricao;

                $contact = PersonContact::create(
                    $this->sanitize([
                        'person_id' => $email->pessoa_id,
                        'contact_type_id' => $contactTypeId,
                        'contact' => $email->email,
                        'from' => lower($type),
                        'status' => lower($status),
                        'provider_enrichment_id' =>
                            $email->enriquecimento_provedor_id,
                        'email_id' => $email->email_id,
                    ])
                );

                $this->increment(
                    'EMAILS',
                    100,
                    "{$email->email} - {$email->email_id}"
                );
            });

        DB::statement(
            "SELECT setval('public.person_contacts_id_seq', (SELECT max(id) FROM public.person_contacts));"
        );
    }

    protected function people()
    {
        $this->counter = 0;

        if (
            Person::count() ==
            $this->db()
                ->table('pessoa')
                ->count()
        ) {
            $this->info('PEOPLE: done');

            return;
        }

        $this->info('Importing PEOPLE...');

        Person::truncate();

        $this->db()
            ->table('pessoa')
            ->get()
            ->each(function ($person) {
                Person::insert(
                    $this->sanitize([
                        'id' => $person->pessoa_id,
                        'code' => $person->codigo,
                        'name' => $this->fixAccentRecursively($person->nome),
                        'cpf_cnpj' => $person->cpf,
                        'identification' => $person->rg,
                        'birthdate' => $person->nascimento,
                        'gender_id' => $person->sexo,
                        'civil_status_id' => $person->estado_civil_id,
                        'spouse_name' => $person->nome_conjuge,
                        'main_occupation_id' => $person->ocupacao_principal,
                        'scholarship_id' => $person->escolaridade_id,
                        'income' => (float) str_replace(
                            '$',
                            '',
                            $person->renda
                        ),
                        'person_type_id' => $person->tipo_pessoa,
                        'created_at' => $person->inclusao,
                        'updated_by_id' => $person->usuario_id_alteracao,
                    ])
                );

                $this->increment(
                    'PEOPLE',
                    100,
                    "{$person->pessoa_id} - {$person->nome}"
                );
            });

        DB::statement(
            "SELECT setval('public.people_id_seq', (SELECT max(id) FROM public.people));"
        );
    }

    protected function info($message)
    {
        $this->command->info($message);
    }

    protected function db()
    {
        return DB::connection('cercred');
    }

    private function getProtocolsForPerson($person)
    {
        return coollect(
            $this->db()->select(
                "
select distinct
  pessoa.pessoa_id,
  pessoa.nome pessoa_nome,
  protocolo.protocolo_id,
  protocolo.protocolo_codigo,
  protocolo.objeto_id,
  protocolo.historico_id,
  protocolo.historico_id_finalizador
from cercred.protocolo, cercred.objeto, cercred.pessoa
where protocolo.objeto_id = objeto.objeto_id and objeto.pessoa_id = pessoa.pessoa_id and pessoa.pessoa_id = {$person->id}
order by protocolo.protocolo_id
"
            )
        );
    }

    private function getHistory($objetoId, $field = 'objeto_id')
    {
        $objetoId = (array) $objetoId;

        if (count($objetoId) == 0) {
            return coollect();
        }
        return coollect(
            $this->db()->select(
                "select 
  DISTINCT
  historico.historico_id,
  historico.complemento historico_complemento,
  historico_tipo.descricao historico_tipo_descricao,
  historico.usuario_id_alteracao historico_usuario_id_alteracao,
  historico_tipo.historico_tipo,
  historico_tipo.descricao historico_tipo_descricao,
  historico.data_inicio_atendimento historico_data_inicio_atendimento,
  action.action_id action_id,
  action.description action_description,
  action.action_type action_type,
  action_type.description action_type_description
from historico
  left join historico_propriedade on historico_propriedade.historico_id = historico.historico_id
  left join historico_propriedade_tipo on historico_propriedade_tipo.historico_propriedade_tipo = historico_propriedade.historico_propriedade_tipo
  left join historico_tipo on historico.historico_tipo = historico_tipo.historico_tipo
  left join action_historico on historico_tipo.historico_tipo = action_historico.historico_tipo
  left join action on action.action_id = action_historico.action_id
  left join action_type on action_type.action_type = action.action_type
where historico.imported = false 
and historico.{$field} in (" .
                    implode(',', $objetoId) .
                    ');'
            )
        );
    }

    private function getHistoryFields($historyId)
    {
        return coollect(
            $this->db()->select(
                "select
  historico_propriedade.historico_propriedade_id,
  historico_propriedade.valor historico_propriedade_valor,
  historico_propriedade_tipo.historico_propriedade_tipo,
  historico_propriedade_tipo.descricao historico_propriedade_tipo_descricao
from historico
  left join historico_propriedade on historico_propriedade.historico_id = historico.historico_id
  left join historico_propriedade_tipo on historico_propriedade_tipo.historico_propriedade_tipo = historico_propriedade.historico_propriedade_tipo
  left join historico_tipo on historico.historico_tipo = historico_tipo.historico_tipo
  left join action_historico on historico_tipo.historico_tipo = action_historico.historico_tipo
  left join action on action.action_id = action_historico.action_id
  left join action_type on action_type.action_type = action.action_type
where historico.historico_id = {$historyId};"
            )
        );
    }

    public function sanitize($array)
    {
        foreach ($array as $key => $value) {
            if (is_string($value)) {
                $array[$key] = trim($value);
            }
        }

        return $array;
    }

    public function findActionByHistoryId($historyId)
    {
        if (empty($historyId)) {
            return;
        }

        return coollect(
            $this->db()->select(
                "select
  historico.historico_id,
  action.action_id action_id,
  action.description action_description,
  action.action_type action_type,
  action_type.description action_type_description
from historico
  left join historico_tipo on historico.historico_tipo = historico_tipo.historico_tipo
  left join action_historico on historico_tipo.historico_tipo = action_historico.historico_tipo
  left join action on action.action_id = action_historico.action_id
  left join action_type on action_type.action_type = action.action_type
where historico_id = {$historyId}"
            )
        )->first();
    }

    public function increment($counterName, $mod = 100, $message = '')
    {
        if (!isset($this->counter[$counterName])) {
            $this->counter[$counterName] = 0;
        }

        $this->counter[$counterName]++;
        if (
            $this->counter[$counterName] == 1 ||
            $this->counter[$counterName] % $mod === 0
        ) {
            $counter = str_pad(
                $this->counter[$counterName],
                8,
                ' ',
                STR_PAD_LEFT
            );

            $memory = str_pad(
                number_format(memory_get_peak_usage()),
                13,
                ' ',
                STR_PAD_LEFT
            );

            $this->info(
                "{$counterName} - {$counter} records {$memory} bytes = {$message}"
            );
        }
    }

    /**
     * @param $person
     * @return null|string|string[]
     */
    function fixAccent($string)
    {
        return preg_replace_callback(
            '/(.*)(&#[0-9]+)(.*)/',
            function ($m) {
                return $m[1] .
                    mb_convert_encoding($m[2] . ';', 'UTF-8', 'HTML-ENTITIES') .
                    $m[3];
            },
            $string
        );
    }

    function fixAccentRecursively($string)
    {
        while ($string != $this->fixAccent($string)) {
            $string = $this->fixAccent($string);
        }
        return $string;
    }
}
