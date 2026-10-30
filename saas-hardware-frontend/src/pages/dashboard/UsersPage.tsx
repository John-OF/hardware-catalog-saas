import './UsersPage.css';

import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import { avisarErrorEnSesion } from '../../api/erroresDeFormulario';
import {
  Plus,
  Loader2,
  Mail,
  MailCheck,
  UserPlus,
  ShieldCheck,
  Shield,
  Power,
  Trash2,
  Send,
  UserCog,
} from 'lucide-react';
import {
  getTeamUsers,
  inviteUser,
  updateTeamUser,
  deleteTeamUser,
  resendInvitation,
  type RolDePanel,
} from '../../api/users';
import { getPlan } from '../../api/plan';
import { CLAVE_ROLES, getRolesDelEquipo } from '../../api/teamRoles';
import { useAuthStore } from '../../stores/authStore';
import Dialogo from '../../components/ui/Dialogo';
import RolesDelEquipo from './RolesDelEquipo';
import type { PlanInfo, RolDelEquipo, User } from '../../types';

/**
 * El equipo de la tienda (FUN-4) y sus roles (MOD-21).
 *
 * Hasta aquí una tienda era un solo usuario: el del alta. El dueño que tenía un
 * vendedor le pasaba su contraseña, y como el login cierra las sesiones
 * anteriores, los dos se echaban mutuamente todo el día.
 *
 * Dos pestañas. **Personas**: quién entra y con qué acceso —**Administrador**,
 * que lo puede todo, o uno de los roles del equipo—. **Roles y permisos**: qué
 * puede cada rol, acción por acción (`RolesDelEquipo`). La barrera de verdad es
 * el middleware `permiso:` de cada ruta; esta pantalla solo la cuenta.
 *
 * La pestaña va en la URL (`?pestania=roles`), como los filtros del catálogo
 * (UI-1): se puede guardar o pasar el enlace.
 *
 * Solo la ven los administradores (`SoloAdmin` en el router).
 */

/** Lo que va en el desplegable de acceso: `admin` o el id de un rol del equipo. */
const ADMIN = 'admin';

const nombreDeAcceso = (usuario: User): string =>
  usuario.role === 'admin' ? 'Administrador' : usuario.team_role?.name ?? 'Sin rol';

/** El texto bajo el desplegable: qué podrá hacer con lo elegido. */
const pistaDeAcceso = (acceso: string, roles: RolDelEquipo[]): string => {
  if (acceso === ADMIN) {
    return 'Podrá cambiar la configuración, el plan y el equipo, incluido tu propio acceso. Dáselo solo a quien lleve la tienda contigo.';
  }

  const rol = roles.find((r) => r.id === acceso);
  if (!rol) return '';
  if (rol.areas.length === 0) return 'Este rol todavía no tiene ningún permiso: podrá entrar, pero no ver nada.';

  return `${rol.description ? `${rol.description}. ` : ''}Entra a: ${rol.areas.join(', ')}. Puedes cambiarlo más tarde.`;
};

function SelectorDeAcceso({ id, valor, onCambiar, roles }: {
  id: string;
  valor: string;
  onCambiar: (valor: string) => void;
  roles: RolDelEquipo[];
}) {
  return (
    <>
      <select id={id} value={valor} onChange={(e) => onCambiar(e.target.value)} className="premium-input">
        {roles.map((r) => (
          <option key={r.id} value={r.id}>{r.name}</option>
        ))}
        <option value={ADMIN}>Administrador — todo, como tú</option>
      </select>
      <p className="form-hint">{pistaDeAcceso(valor, roles)}</p>
      {roles.length === 0 && (
        <p className="form-hint">
          No hay ningún rol del equipo: créalo en <strong>Roles y permisos</strong> para invitar a alguien con un
          acceso limitado.
        </p>
      )}
    </>
  );
}

export default function UsersPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const pestania = searchParams.get('pestania') === 'roles' ? 'roles' : 'personas';

  const cambiarPestania = (siguiente: 'personas' | 'roles') => {
    const parametros = new URLSearchParams(searchParams);
    if (siguiente === 'roles') parametros.set('pestania', 'roles');
    else parametros.delete('pestania');
    setSearchParams(parametros, { replace: true });
  };

  return (
    <div className="users-page animate-fade-in page-users">
      <div className="team-pestanias" role="tablist" aria-label="Equipo">
        <button
          type="button"
          role="tab"
          aria-selected={pestania === 'personas'}
          className={`team-pestania ${pestania === 'personas' ? 'activa' : ''}`}
          onClick={() => cambiarPestania('personas')}
        >
          Personas
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={pestania === 'roles'}
          className={`team-pestania ${pestania === 'roles' ? 'activa' : ''}`}
          onClick={() => cambiarPestania('roles')}
        >
          Roles y permisos
        </button>
      </div>

      {pestania === 'roles' ? <RolesDelEquipo /> : <PersonasDelEquipo />}
    </div>
  );
}

function PersonasDelEquipo() {
  const queryClient = useQueryClient();
  const yo = useAuthStore((s) => s.user);

  const [isModalOpen, setIsModalOpen] = useState(false);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  // Un rol del equipo por defecto: dar poder de administrador tiene que ser una
  // decisión, no lo que pasa si nadie toca el desplegable.
  const [acceso, setAcceso] = useState<string>('');
  const [cambiandoAcceso, setCambiandoAcceso] = useState<User | null>(null);
  const [accesoNuevo, setAccesoNuevo] = useState<string>('');

  const { data: usuarios = [], isLoading } = useQuery<User[]>({
    queryKey: ['teamUsers'],
    queryFn: getTeamUsers,
  });

  const { data: roles = [] } = useQuery({ queryKey: CLAVE_ROLES, queryFn: getRolesDelEquipo });
  const accesoPorDefecto = roles[0]?.id ?? ADMIN;

  // Para pintar "2 / 3" antes de que el dueño choque con el tope.
  const { data: plan } = useQuery<PlanInfo>({ queryKey: ['plan'], queryFn: getPlan });

  const tope = plan?.limits?.users;
  const usados = plan?.usage?.users ?? usuarios.length;
  const sinHueco = typeof tope === 'number' && usados >= tope;

  const refrescar = () => {
    queryClient.invalidateQueries({ queryKey: ['teamUsers'] });
    queryClient.invalidateQueries({ queryKey: ['plan'] });
  };

  const alFallar = (err: unknown, porDefecto: string) => {
    avisarErrorEnSesion(err, porDefecto);
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

  const cambiarRolMutation = useMutation({
    mutationFn: ({ id, valor }: { id: string; valor: string }) =>
      updateTeamUser(id, valor === ADMIN ? { role: 'admin' } : { role: 'staff' as RolDePanel, team_role_id: valor }),
    onSuccess: (usuario) => {
      refrescar();
      // El número de personas de cada rol cambió.
      queryClient.invalidateQueries({ queryKey: CLAVE_ROLES });
      toast.success(
        usuario.role === 'admin'
          ? `${usuario.name} ahora es administrador`
          : `${usuario.name} ahora tiene el rol «${nombreDeAcceso(usuario)}»`,
      );
      setCambiandoAcceso(null);
    },
    onError: (err) => alFallar(err, 'No se pudo cambiar el rol'),
  });

  const eliminarMutation = useMutation({
    mutationFn: deleteTeamUser,
    onSuccess: () => {
      refrescar();
      queryClient.invalidateQueries({ queryKey: CLAVE_ROLES });
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
    setAcceso(accesoPorDefecto);
    setIsModalOpen(true);
  };

  const cerrarModal = () => setIsModalOpen(false);

  const enviarInvitacion = (e: React.FormEvent) => {
    e.preventDefault();

    if (!name.trim() || !email.trim()) {
      toast.error('Escribe el nombre y el correo de la persona.');
      return;
    }

    const elegido = acceso || accesoPorDefecto;
    invitarMutation.mutate(
      elegido === ADMIN
        ? { name: name.trim(), email: email.trim(), role: 'admin' }
        : { name: name.trim(), email: email.trim(), role: 'staff', team_role_id: elegido },
    );
  };

  const abrirCambioDeAcceso = (usuario: User) => {
    setAccesoNuevo(usuario.role === 'admin' ? ADMIN : usuario.team_role_id ?? accesoPorDefecto);
    setCambiandoAcceso(usuario);
  };

  const guardarAcceso = (e: React.FormEvent) => {
    e.preventDefault();
    if (!cambiandoAcceso) return;

    const actual = cambiandoAcceso.role === 'admin' ? ADMIN : cambiandoAcceso.team_role_id ?? '';
    if (accesoNuevo === actual) {
      setCambiandoAcceso(null);
      return;
    }

    const aviso = accesoNuevo === ADMIN
      ? `¿Hacer administrador a ${cambiandoAcceso.name}? Podrá cambiar la configuración y el equipo, incluido tu propio acceso.`
      : `¿Darle a ${cambiandoAcceso.name} el rol «${roles.find((r) => r.id === accesoNuevo)?.name ?? ''}»?`;

    if (window.confirm(`${aviso} Se le cerrará la sesión para que entre con su nuevo acceso.`)) {
      cambiarRolMutation.mutate({ id: cambiandoAcceso.id, valor: accesoNuevo });
    }
  };

  const eliminar = (usuario: User) => {
    if (window.confirm(
      `¿Quitar a ${usuario.name} del equipo? Perderá el acceso al panel. Si solo quieres pausarlo, desactívalo en vez de eliminarlo.`
    )) {
      eliminarMutation.mutate(usuario.id);
    }
  };

  return (
    <>
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

      {/* Lo que puede cada uno, dicho antes de invitar y no descubierto después. */}
      <div className="team-notice glass-card">
        <ShieldCheck size={18} />
        <p>
          Un <strong>administrador</strong> puede todo lo que puedes tú. Los demás tienen un <strong>rol del
          equipo</strong>, y lo que puede cada rol —ver, editar, borrar, exportar, ver el costo— se decide en
          la pestaña <strong>Roles y permisos</strong>.
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
                  <span className={`badge ${usuario.role === 'admin' ? 'badge-rol-admin' : 'badge-neutro'}`}>
                    {usuario.role === 'admin' ? <ShieldCheck size={12} /> : <Shield size={12} />}
                    {nombreDeAcceso(usuario)}
                  </span>

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
                        onClick={() => abrirCambioDeAcceso(usuario)}
                        className="btn-secondary team-btn"
                        disabled={cambiarRolMutation.isPending}
                      >
                        <UserCog size={15} /> Cambiar acceso
                      </button>
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
        <Dialogo titulo="Invitar a alguien" onCerrar={cerrarModal} className="page-users">
            <form onSubmit={enviarInvitacion} className="dialogo-cuerpo">
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

              <div className="form-group">
                <label htmlFor="user-role">Acceso</label>
                <SelectorDeAcceso id="user-role" valor={acceso || accesoPorDefecto} onCambiar={setAcceso} roles={roles} />
              </div>

              <div className="dialogo-acciones">
                <button type="button" onClick={cerrarModal} className="btn-secondary">
                  Cancelar
                </button>
                <button type="submit" disabled={invitarMutation.isPending} className="btn-primary">
                  {invitarMutation.isPending ? <Loader2 className="spinner" size={16} /> : <UserPlus size={16} />}
                  Enviar invitación
                </button>
              </div>
            </form>
        </Dialogo>
      )}

      {cambiandoAcceso && (
        <Dialogo
          titulo={`Acceso de ${cambiandoAcceso.name}`}
          onCerrar={() => setCambiandoAcceso(null)}
          className="page-users"
        >
          <form onSubmit={guardarAcceso} className="dialogo-cuerpo">
            <div className="form-group">
              <label htmlFor="user-role-nuevo">Acceso</label>
              <SelectorDeAcceso id="user-role-nuevo" valor={accesoNuevo} onCambiar={setAccesoNuevo} roles={roles} />
            </div>

            <div className="dialogo-acciones">
              <button type="button" onClick={() => setCambiandoAcceso(null)} className="btn-secondary">
                Cancelar
              </button>
              <button type="submit" disabled={cambiarRolMutation.isPending} className="btn-primary">
                {cambiarRolMutation.isPending && <Loader2 className="spinner" size={16} />}
                Guardar
              </button>
            </div>
          </form>
        </Dialogo>
      )}
    </>
  );
}
