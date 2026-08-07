<?php

namespace App\Notifications\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationAccountCreated extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $token,
        public Team $team,
        public TeamRole $role,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        return (new MailMessage)
            ->subject('Configura tu acceso a C89 Academy')
            ->greeting("Hola, {$notifiable->name}")
            ->line("Se creó tu acceso a la organización {$this->team->name} con el rol de {$this->role->label()}.")
            ->line('Para proteger tu cuenta, establece tu propia contraseña mediante el siguiente enlace.')
            ->action('Establecer mi contraseña', route('password.reset', [
                'token' => $this->token,
                'email' => $notifiable->email,
            ]))
            ->line('Si no reconoces esta alta, comunícate con el administrador de tu organización.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'team_id' => $this->team->id,
            'team_name' => $this->team->name,
            'role' => $this->role->value,
        ];
    }
}
