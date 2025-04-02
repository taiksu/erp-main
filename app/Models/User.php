<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;


    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'unidade_id',  // Novo campo de unidade
        'pin',         // Novo campo para PIN
        'cpf',         // Novo campo para CPF
        'profile_photo_path',
        'cargo_id',    // Novo campo para cargo
        'salario',     // Novo campo para salário
        'franqueado',
        'colaborador'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Relacionamento com a tabela de historico
     */

    public function historicoPedidos()
    {
        return $this->hasMany(HistoricoPedido::class, 'usuario_responsavel_id');
    }


    /**
     * Relacionamento com a tabela de UserDetails
     */
    public function userDetails()
    {
        return $this->hasOne(UserDetails::class);
    }

    /**
     * Relacionamento com a tabela de Unidade
     */
    public function unidade()
    {
        return $this->belongsTo(InforUnidade::class, 'unidade_id');
    }



    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function userPermission()
    {
        return $this->hasOne(UserPermission::class);
    }

    public function cargo()
    {
        return $this->belongsTo(Cargo::class); // Assumindo que o usuário tem um cargo
    }

    /**
     * Sistema de permissões
     */

     public function hasPermission(string $permission): bool
    {
        $permissions = $this->getPermissions();
        return isset($permissions[$permission]) && $permissions[$permission] === 1;
    }

    public function getPermissions(): array
    {
        return UserPermission::getPermissions($this->id);
    }

    
}
