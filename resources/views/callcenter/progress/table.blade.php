<div class="card mt-4">
    <div class="card-header">
        <div class="row">
            <div class="col-4">
                <h5>
                    {{ __('Andamentos') }}
                </h5>
            </div>

            <div class="col-8 text-right">
                <a id="buttonEndereços" href="{{ route('progresses.create',['record_id' => $record->id]) }}"
                   class="btn btn-primary btn-sm pull-right">
                    <i class="fa fa-plus"></i>
                    Novo Andamento
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">

        <table id="progressesTable" class="table table-striped table-bordered" cellspacing="0" width="100%">
            <thead>
            <tr>
                <th>Tipo de Andamento</th>
                <th>Origem</th>
                <th>Área</th>
                <th>Solicitação</th>
                <th>Opções</th>
            </tr>
            </thead>

            @forelse ($progresses as $progress)
                <tr>
                    <td>
                        <a class="btn btn-success" href="{{$progress->show_link}} ? 'N/C'">
                            <i class="fa fa-external-link-square"></i>
                        </a>
                    </td>
                    <td>
                        @if(is_null($progress->progressType))
                            N/C
                        @else
                            {{$progress->progressType->name}}
                        @endIf
                    </td>
                    <td>
                        @if(is_null($progress->recordType))
                            N/C
                        @else
                            {{ $progress->origin->name }}
                        @endIf
                    </td>
                    <td>
                        @if(is_null($progress->area))
                            N/C
                        @else
                            {{$progress->area->name}}
                        @endIf
                    </td>
                    <td>
                        {{$progress->original}}
                    </td>
                    <td>
                        <a class="btn btn-success" href="{{$progress->show_link}}">
                            <i class="fa fa-search"></i>
                        </a>
                    </td>
                </tr>
            @empty
                <p>Nenhum andamento encontrado.</p>
            @endforelse
        </table>
        {{ $progresses->links() }}
    </div>
</div>
