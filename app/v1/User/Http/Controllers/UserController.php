<?php

namespace App\v1\User\Http\Controllers;

use App\v1\User\Models\User;
use App\Helpers\LogText;
use App\Http\Controllers\Controller;
use App\v1\User\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;

class UserController extends Controller
{

    public function  getAllUsers(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        
        $users = User::get();
       
        return UserResource::collection($users);
    }

    public function getUsersDatatable(): \Illuminate\Http\JsonResponse
    {
        $columns = array(
            0 => 'lastname',
            1 => 'firstname',
            2 => 'email',
            3 => 'role_name',
        );

        $totalData = User::where('id', '!=', auth()->user()->id)->count();


        $limit = request()->input('length');
        $start = request()->input('start');
        $order = $columns[request()->input('order.0.column')];
        $dir = request()->input('order.0.dir');
        $search = request()->input('search.value') ;

        $users = User::with(['roles'])
            ->where('id', '!=', auth()->user()->id)
            ->when($search , function($query) use ($search){
                $query->where(function ($query) use ($search) {
                    $query->where('email', 'ILIKE', "%{$search}%")
                          ->orWhereHas('person', function ($query) use ($search) {
                                $query->where('firstname', 'ILIKE', "%{$search}%")
                                    ->orWhere('lastname', 'ILIKE', "%{$search}%") ;
                          })
                          ->orWhereHas('roles', function ($query) use ($search) {
                                $query->where('roles.name', 'ILIKE', "%{$search}%");
                            })
                    ;
                });
            })
            ->get()
        ;

        $totalFiltered = User::with(['roles'])
            ->where('id', '!=', auth()->user()->id)
            ->when($search , function($query) use ($search){
                $query->where(function ($query) use ($search) {
                    $query->where('email', 'ILIKE', "%{$search}%")
                        ->orWhereHas('person', function ($query) use ($search) {
                            $query->where('firstname', 'ILIKE', "%{$search}%")
                                ->orWhere('lastname', 'ILIKE', "%{$search}%") ;
                        })
                        ->orWhereHas('roles', function ($query) use ($search) {
                            $query->where('roles.name', 'ILIKE', "%{$search}%");
                        })
                    ;
                });
            })
           ->count();


        if($order == 'role_name') {
            $users = $users->map(function ($user) {
                if ($user->roles->first()) {
                    $user->setAttribute('role_name', $user->roles->first()->name);
                }
                return $user;
            });
        }

        $users = $users->sortBy($order,SORT_NATURAL | SORT_FLAG_CASE ,$dir != 'asc') ;
        $users = $users->slice($start,$limit);

        $data = array();

        if (!empty($users)) {
            foreach ($users as $user) {
                $nestedData['id'] = $user->id;
                $nestedData['gender'] = $user->person?->gender;
                $nestedData['lastname'] = $user->person?->lastname;
                $nestedData['firstname'] = $user->person?->firstname;
                $nestedData['email'] = $user->email;
                $nestedData['role_name'] = optional($user->roles->first())->name ?? "";
                $nestedData['role_id'] =  optional($user->roles->first())->id ?? "" ;
                $nestedData['Actions'] = null;
                $data[] = $nestedData;
            }
        }

        $json_data = array(
            "draw" => intval(request()->input('draw')),
            "recordsTotal" => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data" => $data
        );

        return response()->json($json_data, 200);
    }

    public function store(UserRequest $request): UserResource|\Illuminate\Http\JsonResponse
    {
        $user = User::createFromRequest($request);
        $role = request('role') ;
        if($role){
            $role = Role::find($role);
            $this->addRoleToUser($user,$role);
        }

        $actionDetail = LogText::USER_CREATION. ' ' . $user->fullname;
        AccessLog::logAction(auth()->user(), User::class, $user->id, $actionDetail);

        return new UserResource($user);
    }

    public function show($user): UserResource|\Illuminate\Http\JsonResponse
    {
        $user = User::findOrFail($user);
        return new UserResource($user);
    }

    public function getUsersPaginate(Request $request): \Illuminate\Http\JsonResponse|\Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $limit = $request->has('limit') ? $request->limit : 'all';
        $user = $request->user();

        $users = User::applyFilters($request->all())
            ->where('id', '<>', $user->id)
            ->paginateData($limit)
        ;

        return UserResource::collection($users);
    }

    public function update(UserRequest $request, $user): UserResource|\Illuminate\Http\JsonResponse
    {
        $user = User::findOrFail($user);
        $oldUser = clone $user;
        $user->updateFromRequest($request);

        $role = request('role') ;
        if($role && !in_array($role,$user->roles->pluck('id')->toArray())){
            $role = Role::find($role);
            $this->addRoleToUser($user,$role);
        }

        $actionDetail = LogText::USER_UPDATE. ' ' . $oldUser->fullname;
        $actionDetail .= LogText::OLD_DATA.  ' ' . json_encode($oldUser);
        $actionDetail .= LogText::NEW_DATA. ' ' . json_encode($user);

        AccessLog::logAction(auth()->user(), User::class, $user->id, $actionDetail);

        return new UserResource($user);
    }

    public function delete(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|exists:users,id'
        ]);
        $users = User::whereIn('id', $request->ids)->get();

        User::deleteUsers($request->ids);

        foreach ($users as $user) {
            $actionDetail = LogText::USER_DELETE. ' ' . $user->fullname;
            AccessLog::logAction(auth()->user(), User::class, $user->id, $actionDetail);
        }
        return response()->json([
            'success' => true,
        ]);
    }

    public function deleteUser(User $user)
    {
        if($user->id == request()->user()->id ){
            abort('422', 'Cet utilisateur ne peut etre supprimé');
        }

        $actionDetail = 'Suppression de l\'utilisateur ' . $user->fullname;

        AccessLog::logAction(auth()->user(), User::class, $user->id, $actionDetail);

        $user->delete();

        return response()->json(['message' => 'User deleted'], 200);
    }

    public function getUserWithPermissions(User $user): \Illuminate\Http\JsonResponse
    {
        $permissions =  $this->getUserPermissions($user);
        $role = $user->roles->first() ;
        $user->unsetRelation('permissions');
        $user->load('person');

        return response()->json([
            'user' => $user,
            'role' => $role,
            'permissions' => $permissions
        ]);
    }

    public function check($permissionName): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        if (auth()->user()->hasRole('Admin')) {
            return response('', 204);
        } else {
            $permissions = auth()->user()->permissions->map->name->unique();
            if (!$permissions->contains($permissionName)) {
                abort(403, 'forbidden');
            }
            return response('', 204);
        }
    }

    public function import()
    {
        request()->validate([
            'file' => 'required|mimes:xlsx,csv,tsv,ods,xls,slk'
        ]);

        $file = request()->file('file');
        $ext = strtoupper(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        Excel::import(new UserImport, $file, constant('\Maatwebsite\Excel\Excel::' . $ext));

        return response()->json([
            'message' => 'File imported successfully'
        ]);
    }

    public function updateProfile(): \Illuminate\Http\JsonResponse
    {

        request()->validate([
            'firstname' => 'required|string|max:255',
            'lastname' => 'required|string|max:255',
            'email' => 'required|email|string|max:255|unique:users,email,' . request()->user()->id,
        ]);

        $user = request()->user() ;


        $user->update([
            'email' => request('email')
        ]);

        $user->updatePerson([
            'firstname' => request('firstname'),
            'lastname' => request('lastname'),
        ],$user->person);

        return response()->json([
            'message' => 'Profil successfully updated!'
        ], 200);

    }

    public function getUser(){
        $user = User::with('person')->find(request()->user()->id);
        return response()->json(['user' => new UserResource($user)]);
    }

    public function updatePassword(): \Illuminate\Http\JsonResponse
    {
        request()->validate([
            'old_password' =>['required',function($attr,$value,$fail){
                if(!Hash::check($value,request()->user()->password)){
                    $fail("Mot de passe courant invalide");
                }
            }],
            'password' => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        request()->user()->update([
            'password' => bcrypt(request('password'))
        ]);

        request()->user()->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Mot de passe modifié avec succes'
        ], 200);

    }

    public function setUserAsContact (string $user): \Illuminate\Http\JsonResponse
    {
        DB::beginTransaction();
        $user = User::find($user);
        if(!$user){
            return response()->json([
                'message' => 'User not found'
            ],422);
        }
        $contact = Contact::where('person_id',$user->person->id)->first();

        if($contact){
            return response()->json([
                'message' => 'Cet utilisateur est déjà un contact'
            ],422);
        }

        Contact::create([
            'email' => $user->email,
            'person_id' => $user->person->id,
            'password' => Hash::make('password')
        ]);
        DB::commit();

        return response()->json([
            'message' => 'Operation éffectuée avec succès'
        ],201);
    }

    public function checkUserAlreadyContact (string $user): \Illuminate\Http\JsonResponse
    {
        $user = User::find($user);
        if(!$user){
            return response()->json([
                'message' => 'User not found'
            ],422);
        }
        $contact = Contact::where('person_id',$user->person->id)->first();

        if($contact){
            return response()->json([
                'success' => false,
            ],200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cet utilisateur est déjà un contact'
        ],200);
    }
}
