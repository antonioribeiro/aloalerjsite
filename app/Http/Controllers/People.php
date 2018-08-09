<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\PersonRequest;

class People extends Controller
{
    /**
     * @param Request     $request
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request)
    {
        $pesquisa = $request->get('pesquisa');

        if ($pesquisa) {
            $person = $this->peopleRepository->findByColumn(
                'cpf_cnpj',
                $pesquisa
            );
            if ($person) {
                $records = $this->recordsRepository->findByPerson($person->id);
                $addresses = $this->peopleAddressesRepository->findByPerson(
                    $person->id
                );
                $contacts = $this->peopleContactsRepository->findByPerson(
                    $person->id
                );

                return view('callcenter.people.form')
                    ->with('person', $person)
                    ->with('records', $records)
                    ->with('addresses', $addresses)
                    ->with('contacts', $contacts)
                    ->with(['origins' => $this->originsRepository->all()]);
            } else {
                dd("pessoa não encontrada");
            }
        } else {
            return view('callcenter.people.index');
        }
    }

    /**
     * @return $this
     */
    public function create()
    {
        return view('callcenter.people.form')
            ->with(['person' => $this->peopleRepository->new()])
            ->with($this->getComboBoxMenus())
            ->with('workflow', '1');
    }

    /**
     * @param Request     $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(PersonRequest $request)
    {
        $person_id = $this->userAlreadyRegistered($request);

        $url = 'callcenter.people.form';
        $message = $this->messageDefault;
        if (!$person_id) {
            $url = 'callcenter.records.form';
            $message = 'Usuário cadastrado com sucesso.';
        }
        $view = view($url);
        $view->with($this->getComboBoxMenus());

        if ($person_id) {
            $person = $this->peopleRepository->findById($person_id);
            $records = $this->recordsRepository->findByPerson($person->id);
            $addresses = $this->peopleAddressesRepository->findByPerson(
                $person->id
            );
            $contacts = $this->peopleContactsRepository->findByPerson(
                $person->id
            );

            $view
                ->with('records', $records)
                ->with('addresses', $addresses)
                ->with('contacts', $contacts);
        } else {
            $view
                ->with(['record' => $this->recordsRepository->new()])
                ->with('workflow', $request->get('workflow'));
        }

        $request->merge(['id' => $person_id]);
        $person = $this->peopleRepository->createFromRequest($request);

        return $view
            ->with('person', $person)
            ->with('message', $message);
    }

    /**
     * @param $cpf_cnpj
     *
     * @return $this
     */
    public function show($id)
    {
        $person = $this->peopleRepository->findById($id);
        $records = $this->recordsRepository->findByPerson($person->id);
        $addresses = $this->peopleAddressesRepository->findByPerson(
            $person->id
        );
        $contacts = $this->peopleContactsRepository->findByPerson($person->id);

        return view('callcenter.people.form')
            ->with('person', $person)
            ->with('records', $records)
            ->with('addresses', $addresses)
            ->with('contacts', $contacts)
            ->with(['origins' => $this->originsRepository->all()]);
    }

    /**
     * @param PersonRequest $request
     * @return $person_id
     */
    private function userAlreadyRegistered(PersonRequest $request)
    {
        $person = null;
        if (!$request->get('$person_id') and ($request->get('cpf_cnpj'))) {
            $person = $this->peopleRepository->findByColumn(
                'cpf_cnpj',
                $request->get('cpf_cnpj')
            );
        }
        return $person ? $person->id : null;
    }
}
