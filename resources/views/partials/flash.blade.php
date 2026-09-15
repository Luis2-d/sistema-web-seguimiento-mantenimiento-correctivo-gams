@if(session('success'))<div class="alert alert-success alert-dismissible"><button class="close" data-dismiss="alert">&times;</button><i class="fas fa-check-circle mr-2"></i>{{ session('success') }}</div>@endif
@if(session('warning'))<div class="alert alert-warning" role="alert">{{ session('warning') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><strong>Revise los datos:</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
