import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import { Loader2, Pencil, Plus, Shield, Trash2 } from 'lucide-react';
import { avisarErrorEnSesion } from '../../api/erroresDeFormulario';
import {
  actualizarRolDelEquipo,
  borrarRolDelEquipo,
  CLAVE_ROLES,
  crearRolDelEquipo,
  getCatalogoDePermisos,
  getRolesDelEquipo,
} from '../../api/teamRoles';
import Dialogo from '../../components/ui/Dialogo';
import type { RolDelEquipo } from '../../types';
import { marcarArea, marcarPermiso, requisitosDe, type AreaDePermisos, type Permiso } from '../../utils/permisos';

/**
 * La pestaña «Roles y permisos» de Equipo (MOD-21).
 *
 * Un rol es un nombre y un conjunto de permisos por acción; a cada persona del
 * equipo se le da uno. Editar un rol cambia lo que pueden todos los que lo
 * tienen —por eso existen los roles en vez de casillas por persona—, y si
 * cambian sus permisos el servidor les cierra la sesión para que el panel se
 * les vuelva a pintar con su acceso nuevo.
 *
 * Las etiquetas de los permisos y lo que requiere cada uno vienen del servidor
 * (`GET /permissions`); aquí no hay copia. Marcar uno marca lo que requiere, y
 * desmarcarlo quita lo que dependía de él: la misma regla que aplica el
 * servidor al guardar.
 *
 * Va dentro de la raíz de `UsersPage`, así que usa sus estilos (`.page-users`).
 */
export default function RolesDelEquipo() {
  const queryClient = useQueryClient();
  const [editando, setEditando] = useState<RolDelEquipo | 'nuevo' | null>(null);

  const { data: roles = [], isLoading } = useQuery({ queryKey: CLAVE_ROLES, queryFn: getRolesDelEquipo });
  const { data: areas = [] } = useQuery({
    queryKey: ['catalogoDePermisos'],
    queryFn: getCatalogoDePermisos,
    staleTime: Infinity,
  });

  const borrarMutation = useMutation({
    mutationFn: borrarRolDelEquipo,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: CLAVE_ROLES });
      toast.success('Rol borrado');
    },
    onError: (err) => avisarErrorEnSesion(err, 'No se pudo borrar el rol'),
  });

  const borrar = (rol: RolDelEquipo) => {
    if (window.confirm(`¿Borrar el rol «${rol.name}»? Nadie lo tiene, así que no le cambia el acceso a nadie.`)) {
      borrarMutation.mutate(rol.id);
    }
  };

  return (
    <>
      <div className="page-header-actions">
        <p className="page-description">
          Cada rol es un conjunto de permisos. Dale un rol a cada persona del equipo: si cambias lo que puede
          un rol, cambia para todos los que lo tienen.
        </p>
        <button onClick={() => setEditando('nuevo')} className="btn-primary">
          <Plus size={18} />
          <span>Nuevo rol</span>
        </button>
      </div>

      <div className="team-notice glass-card">
        <Shield size={18} />
        <p>
          El <strong>administrador</strong> no es un rol: lo puede todo y no se le quita nada. El equipo y sus
          roles los gestiona solo un administrador, para que nadie pueda darse a sí mismo un permiso que no
          tiene.
        </p>
      </div>

      {isLoading ? (
        <div className="inner-loader">
          <Loader2 className="spinner" size={32} />
          <p>Cargando los roles...</p>
        </div>
      ) : roles.length === 0 ? (
        <div className="state-card glass-card">
          <h3>Todavía no hay roles</h3>
          <p>Crea uno para invitar a alguien con un acceso limitado. Sin roles solo puedes invitar administradores.</p>
        </div>
      ) : (
        <div className="team-grid">
          {roles.map((rol) => (
            <div key={rol.id} className="team-card glass-card">
              <div className="team-card-head">
                <div className="team-avatar"><Shield size={18} /></div>
                <div className="team-identity">
                  <h3>{rol.name}</h3>
                  <span className="team-email">{rol.description || 'Sin descripción'}</span>
                </div>
              </div>

              <div className="team-badges">
                <span className="badge badge-neutro">
                  {rol.members_count === 1 ? '1 persona' : `${rol.members_count} personas`}
                </span>
                {rol.areas.length === 0 ? (
                  <span className="badge badge-pendiente">Sin ningún permiso</span>
                ) : (
                  rol.areas.map((area) => (
                    <span key={area} className="badge badge-neutro">{area}</span>
                  ))
                )}
              </div>

              <div className="team-actions">
                <button onClick={() => setEditando(rol)} className="btn-secondary team-btn">
                  <Pencil size={15} /> Editar permisos
                </button>
                <button
                  onClick={() => borrar(rol)}
                  className="team-btn team-btn-danger"
                  disabled={rol.members_count > 0 || borrarMutation.isPending}
                  title={rol.members_count > 0 ? 'Alguien tiene este rol: cámbiale el rol antes de borrarlo.' : 'Borrar el rol'}
                >
                  <Trash2 size={15} />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {editando !== null && (
        <EditorDeRol
          rol={editando === 'nuevo' ? null : editando}
          areas={areas}
          onCerrar={() => setEditando(null)}
        />
      )}
    </>
  );
}

function EditorDeRol({ rol, areas, onCerrar }: { rol: RolDelEquipo | null; areas: AreaDePermisos[]; onCerrar: () => void }) {
  const queryClient = useQueryClient();
  const [nombre, setNombre] = useState(rol?.name ?? '');
  const [descripcion, setDescripcion] = useState(rol?.description ?? '');
  const [permisos, setPermisos] = useState<Permiso[]>(rol?.permissions ?? []);

  const guardarMutation = useMutation({
    mutationFn: () => {
      const datos = { name: nombre.trim(), description: descripcion.trim() || null, permissions: permisos };
      return rol ? actualizarRolDelEquipo(rol.id, datos) : crearRolDelEquipo(datos);
    },
    onSuccess: (guardado) => {
      queryClient.invalidateQueries({ queryKey: CLAVE_ROLES });
      queryClient.invalidateQueries({ queryKey: ['teamUsers'] });
      toast.success(rol ? `Rol «${guardado.name}» guardado` : `Rol «${guardado.name}» creado`);
      onCerrar();
    },
    onError: (err) => avisarErrorEnSesion(err, 'No se pudo guardar el rol'),
  });

  const cambiaPermisos = rol !== null
    && [...rol.permissions].sort().join() !== [...permisos].sort().join();

  const guardar = (e: React.FormEvent) => {
    e.preventDefault();

    if (!nombre.trim()) {
      toast.error('Escribe el nombre del rol.');
      return;
    }

    if (rol && cambiaPermisos && rol.members_count > 0) {
      const quien = rol.members_count === 1 ? 'La persona que lo tiene tendrá' : `Las ${rol.members_count} personas que lo tienen tendrán`;
      if (!window.confirm(`${quien} que volver a iniciar sesión para entrar con su acceso nuevo. ¿Guardar?`)) {
        return;
      }
    }

    guardarMutation.mutate();
  };

  return (
    <Dialogo
      titulo={rol ? `Editar «${rol.name}»` : 'Nuevo rol'}
      subtitulo="Marca lo que puede hacer quien tenga este rol."
      onCerrar={onCerrar}
      ancho={640}
      className="page-users"
    >
      <form onSubmit={guardar} className="dialogo-cuerpo">
        <div className="form-group">
          <label htmlFor="rol-nombre">Nombre del rol</label>
          <input
            id="rol-nombre"
            type="text"
            maxLength={40}
            placeholder="ej. Caja, Compras, Vendedor"
            value={nombre}
            onChange={(e) => setNombre(e.target.value)}
            className="premium-input"
            required
          />
        </div>

        <div className="form-group">
          <label htmlFor="rol-descripcion">Descripción <span className="form-opcional">(opcional)</span></label>
          <input
            id="rol-descripcion"
            type="text"
            maxLength={200}
            placeholder="ej. Atiende el mostrador y cobra"
            value={descripcion}
            onChange={(e) => setDescripcion(e.target.value)}
            className="premium-input"
          />
        </div>

        {areas.length === 0 ? (
          <div className="inner-loader">
            <Loader2 className="spinner" size={24} />
          </div>
        ) : (
          <div className="permisos-areas">
            {areas.map((area) => {
              const todos = area.permisos.every((p) => permisos.includes(p.clave));

              return (
                <fieldset key={area.clave} className="permisos-area">
                  <legend>
                    <span>{area.etiqueta}</span>
                    {area.permisos.length > 1 && (
                      <label className="permisos-todo">
                        <input
                          type="checkbox"
                          checked={todos}
                          onChange={(e) => setPermisos(marcarArea(permisos, area, e.target.checked, areas))}
                        />
                        Todo
                      </label>
                    )}
                  </legend>

                  {area.permisos.map((permiso) => {
                    const requisitos = requisitosDe(permiso.clave, areas);

                    return (
                      <label key={permiso.clave} className="permiso-fila">
                        <input
                          type="checkbox"
                          checked={permisos.includes(permiso.clave)}
                          onChange={(e) => setPermisos(marcarPermiso(permisos, permiso.clave, e.target.checked, areas))}
                        />
                        <span>
                          {permiso.etiqueta}
                          {requisitos.length > 0 && (
                            <small className="permiso-requiere">Incluye: {requisitos.join('; ').toLowerCase()}</small>
                          )}
                        </span>
                      </label>
                    );
                  })}
                </fieldset>
              );
            })}
          </div>
        )}

        <p className="form-hint">
          Marcar una acción marca también lo que necesita para hacerse (no se edita lo que no se ve), y
          desmarcarla quita lo que dependía de ella.
        </p>

        <div className="dialogo-acciones">
          <button type="button" onClick={onCerrar} className="btn-secondary">
            Cancelar
          </button>
          <button type="submit" disabled={guardarMutation.isPending} className="btn-primary">
            {guardarMutation.isPending && <Loader2 className="spinner" size={16} />}
            {rol ? 'Guardar cambios' : 'Crear rol'}
          </button>
        </div>
      </form>
    </Dialogo>
  );
}
