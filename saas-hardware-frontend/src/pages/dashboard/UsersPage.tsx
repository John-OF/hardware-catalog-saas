import './UsersPage.css';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import {
  Plus,
  X,
  Loader2,
  Mail,
  MailCheck,
  UserPlus,
  ShieldCheck,
  Power,
  Trash2,
  Send,
} from 'lucide-react';
import {
  getTeamUsers,
  inviteUser,
  updateTeamUser,
  deleteTeamUser,
  resendInvitation,
} from '../../api/users';
import { getPlan } from '../../api/plan';
import { useAuthStore } from '../../stores/authStore';
import { useBloqueoDeScroll } from '../../hooks/useBloqueoDeScroll';
import type { PlanInfo, User } from '../../types';

/**
 * El equipo de la tienda (FUN-4).
 *
 * Hasta aquí una tienda era un solo usuario: el del alta. El dueño que tenía un
 * vendedor le pasaba su contraseña, y como el login cierra las sesiones
 * anteriores, los dos se echaban mutuamente todo el día.
 *
 * Todos los invitados entran como administradores, con el mismo poder que quien
 * les invita. El rol limitado —dar solo stock o solo pedidos— es la segunda
 * mitad de FUN-4 y todavía no existe: por eso el aviso de la cabecera, que dice
 * lo que hay en vez de dejar que se suponga.
 */
export default function UsersPage() {
  const queryClient = useQueryClient();
  const yo = useAuthStore((s) => s.user);

  const [isModalOpen, setIsModalOpen] = useState(false);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');

  // UI-9: con la ventana abierta la pagina de detras no se mueve.
  useBloqueoDeScroll(isModalOpen);

  const { data: usuarios = [], isLoading } = useQuery<User[]>({
    queryKey: ['teamUsers'],
    queryFn: getTeamUsers,
  });

  // Para pintar "2 / 3" antes de que el dueño choque con el tope.
  const { data: plan } = useQuery<PlanInfo>({ queryKey: ['plan'], queryFn: getPlan });

  const tope = plan?.limits?.users;
  const usados = plan?.usage?.users ?? usuarios.length;
  const sinHueco = typeof tope === 'number' && usados >= tope;

  const refrescar = () => {
    queryClient.invalidateQueries({ queryKey: ['teamUsers'] });
    queryClient.invalidateQueries({ queryKey: ['plan'] });
  };

  const alFallar = (err: any, porDefecto: string) => {
    toast.error(err?.response?.data?.message || porDefecto);
  };

  const invitarMutation = useMutation({
    mutationFn: inviteUser,
    onSuccess: (usuario) => {
      refrescar();
      toast.success(`Invitación enviada a ${usuario.email}`);
      cerrarModal();
    },
    onError: (err) => alFallar(err, 'No se pudo enviar la invitación'),
  });

  const cambiarEstadoMutation = useMutation({
    mutationFn: ({ id, is_active }: { id: string; is_active: boolean }) =>
      updateTeamUser(id, { is_active }),
    onSuccess: (usuario) => {
      refrescar();
      toast.success(usuario.is_active ? 'Acceso reactivado' : 'Acceso desactivado');
    },
    onError: (err) => alFallar(err, 'No se pudo cambiar el acceso'),
  });

  const eliminarMutation = useMutation({
    mutationFn: deleteTeamUser,
    onSuccess: () => {
      refrescar();
      toast.success('Persona eliminada del equipo');
    },
    onError: (err) => alFallar(err, 'No se pudo eliminar'),
  });

  const reenviarMutation = useMutation({
    mutationFn: resendInvitation,
    onSuccess: (res) => toast.success(res.message),
    onError: (err) => alFallar(err, 'No se pudo reenviar la invitación'),
  });

  const abrirModal = () => {
    setName('');
    setEmail('');
    setIsModalOpen(true);
  };

  const cerrarModal = () => setIsModalOpen(false);

  const enviarInvitacion = (e: React.FormEvent) => {
    e.preventDefault();

    if (!name.trim() || !email.trim()) {
      toast.error('Escribe el nombre y el correo de la persona.');
      return;
    }

    invitarMutation.mutate({ name: name.trim(), email: email.trim() });
  };

  const eliminar = (usuario: User) => {
    if (window.confirm(
      `¿Quitar a ${usuario.name} del equipo? Perderá el acceso al panel. Si solo quieres pausarlo, desactívalo en vez de eliminarlo.`
    )) {
      eliminarMutation.mutate(usuario.id);
    }
  };

  return (
    <div className="users-page animate-fade-in page-users">
      <div className="page-header-actions">
        <p className="page-description">
          Quién puede entrar al panel de tu tienda. Cada persona entra con su propio correo y su
          propia contraseña, así que nadie echa a nadie al iniciar sesión.
        </p>
        <button onClick={abrirModal} className="btn-primary" disabled={sinHueco}>
          <Plus size={18} />
          <span>Invitar a alguien</span>
        </button>
      </div>

      {/* Decir lo que hay: hoy todos tienen el mismo poder. */}
      <div className="team-notice glass-card">
        <ShieldCheck size={18} />
        <p>
          Por ahora todas las personas que invites tienen <strong>el mismo acceso que tú</strong>:
          pueden ver y cambiar productos, pedidos, configuración y el propio equipo. Los permisos
          limitados —dar acceso solo al stock o solo a los pedidos— están en camino.
        </p>
      </div>

      {typeof tope === 'number' && (
        <p className={`team-quota ${sinHueco ? 'agotada' : ''}`}>
          {usados} de {tope} {tope === 1 ? 'persona' : 'personas'} en tu plan {plan?.label}
          {sinHueco && ' — mejora de plan para invitar a alguien más.'}
        </p>
      )}

      {isLoading ? (
        <div className="inner-loader">
          <Loader2 className="spinner" size={32} />
          <p>Cargando el equipo...</p>
        </div>
      ) : (
        <div className="team-grid">
          {usuarios.map((usuario) => {
            const soyYo = usuario.id === yo?.id;

            return (
              <div key={usuario.id} className={`team-card glass-card ${usuario.is_active ? '' : 'inactivo'}`}>
                <div className="team-card-head">
                  <div className="team-avatar">{usuario.name.charAt(0).toUpperCase()}</div>
                  <div className="team-identity">
                    <h3>
                      {usuario.name}
                      {soyYo && <span className="team-yo">tú</span>}
                    </h3>
                    <span className="team-email">{usuario.email}</span>
                  </div>
                </div>

                <div className="team-badges">
                  {usuario.is_active ? (
                    <span className="badge badge-success">Activo</span>
                  ) : (
                    <span className="badge badge-danger">Sin acceso</span>
                  )}

                  {usuario.invitation_pending ? (
                    <span className="badge badge-pendiente">
                      <Mail size={12} /> Invitación pendiente
                    </span>
                  ) : (
                    <span className="badge badge-neutro">
                      <MailCheck size={12} /> Ya ha entrado
                    </span>
                  )}
                </div>

                <div className="team-actions">
                  {usuario.invitation_pending && (
                    <button
                      onClick={() => reenviarMutation.mutate(usuario.id)}
                      className="btn-secondary team-btn"
                      disabled={reenviarMutation.isPending}
                      title="El enlace de la invitación caduca; esto manda uno nuevo."
                    >
                      <Send size={15} /> Reenviar
                    </button>
                  )}

                  {/* Sobre uno mismo no se ofrece nada: el backend lo rechaza y
                      un botón que siempre falla es peor que no tenerlo. */}
                  {!soyYo && (
                    <>
                      <button
                        onClick={() => cambiarEstadoMutation.mutate({ id: usuario.id, is_active: !usuario.is_active })}
                        className="btn-secondary team-btn"
                        disabled={cambiarEstadoMutation.isPending}
                      >
                        <Power size={15} /> {usuario.is_active ? 'Desactivar' : 'Reactivar'}
                      </button>
                      <button
                        onClick={() => eliminar(usuario)}
                        className="team-btn team-btn-danger"
                        disabled={eliminarMutation.isPending}
                        title="Quitar del equipo"
                      >
                        <Trash2 size={15} />
                      </button>
                    </>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {isModalOpen && (
        <div className="modal-overlay" onClick={cerrarModal}>
          <div className="modal-drawer glass-card animate-slide-up" onClick={(e) => e.stopPropagation()}>
            <div className="drawer-header">
              <h3>Invitar a alguien</h3>
              <button onClick={cerrarModal} className="drawer-close">
                <X size={20} />
              </button>
            </div>

            <form onSubmit={enviarInvitacion} className="drawer-form">
              <div className="form-group">
                <label htmlFor="user-name">Nombre</label>
                <input
                  id="user-name"
                  type="text"
                  placeholder="ej. Carlos Ramírez"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="premium-input"
                  required
                />
              </div>

              <div className="form-group">
                <label htmlFor="user-email">Correo electrónico</label>
                <input
                  id="user-email"
                  type="email"
                  placeholder="ej. carlos@tutienda.com"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="premium-input"
                  required
                />
                <p className="form-hint">
                  Le enviaremos un enlace para que <strong>elija su propia contraseña</strong>. Tú no
                  tienes que inventarle ninguna ni pasársela por WhatsApp.
                </p>
              </div>

              <div className="drawer-actions">
                <button type="button" onClick={cerrarModal} className="btn-secondary">
                  Cancelar
                </button>
                <button type="submit" disabled={invitarMutation.isPending} className="btn-primary">
                  {invitarMutation.isPending ? <Loader2 className="spinner" size={16} /> : <UserPlus size={16} />}
                  Enviar invitación
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
