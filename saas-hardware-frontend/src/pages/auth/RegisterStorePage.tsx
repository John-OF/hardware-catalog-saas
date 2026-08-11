import { useState, useEffect } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { toast } from 'react-hot-toast';
import { Store, Link2, Phone, User, Mail, Lock, Eye, EyeOff, Loader2, Cpu } from 'lucide-react';
import { registerStore } from '../../api/auth';
import { useAuthStore } from '../../stores/authStore';
import { useTenantStore } from '../../stores/tenantStore';

/** Errores de validación del backend, por campo. */
type FieldErrors = Record<string, string[]>;

/** Forma mínima de un 422 de Laravel tal como llega por axios. */
type ApiError = {
  response?: { data?: { message?: string; errors?: FieldErrors } };
};

/**
 * Convierte el nombre de la tienda en un slug candidato.
 * El backend exige ^[a-z0-9-]+$, así que quitamos acentos y todo lo demás.
 */
function slugify(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .slice(0, 80);
}

export default function RegisterStorePage() {
  const navigate = useNavigate();
  const setAuth = useAuthStore((s) => s.setAuth);
  const setTenant = useTenantStore((s) => s.setTenant);
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  const [storeName, setStoreName] = useState('');
  const [slug, setSlug] = useState('');
  const [slugTouched, setSlugTouched] = useState(false);
  const [whatsapp, setWhatsapp] = useState('');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    if (isAuthenticated) {
      navigate('/dashboard', { replace: true });
    }
  }, [isAuthenticated, navigate]);

  // Mientras el dueño no toque el slug a mano, lo derivamos del nombre.
  const handleStoreNameChange = (value: string) => {
    setStoreName(value);
    if (!slugTouched) {
      setSlug(slugify(value));
    }
  };

  const handleSlugChange = (value: string) => {
    setSlugTouched(true);
    setSlug(slugify(value));
  };

  const fieldError = (field: string): string | null => errors[field]?.[0] ?? null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});

    if (password !== passwordConfirmation) {
      setErrors({ password: ['Las contraseñas no coinciden.'] });
      return;
    }

    if (!/^[a-z0-9-]+$/.test(slug)) {
      setErrors({ slug: ['Usa solo minúsculas, números y guiones.'] });
      return;
    }

    setIsLoading(true);
    try {
      const response = await registerStore({
        store_name: storeName,
        slug,
        whatsapp,
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });

      setAuth(response.token, response.user);
      setTenant(response.tenant);

      toast.success(`¡Tu tienda ${response.tenant.name} está lista!`);
      navigate('/dashboard', { replace: true });
    } catch (error) {
      const response = (error as ApiError).response;
      // 422 trae los errores por campo (slug reservado o en uso, correo repetido...).
      const validationErrors = response?.data?.errors;

      if (validationErrors) {
        setErrors(validationErrors);
        toast.error('Revisa los datos marcados en rojo.');
      } else {
        console.error(error);
        toast.error(response?.data?.message || 'No pudimos crear la tienda. Inténtalo de nuevo.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="register-container animate-fade-in">
      <div className="register-card glass-card animate-scale-in">
        <div className="register-header">
          <div className="logo-badge">
            <Cpu size={32} />
          </div>
          <h1>Crea tu tienda</h1>
          <p>Publica tu catálogo de componentes en minutos</p>
        </div>

        <form onSubmit={handleSubmit} className="register-form" noValidate>
          <fieldset className="form-section" disabled={isLoading}>
            <legend>Tu tienda</legend>

            <div className="form-group">
              <label htmlFor="store-name">Nombre de la tienda</label>
              <div className="input-wrapper">
                <Store className="input-icon" size={18} />
                <input
                  id="store-name"
                  type="text"
                  placeholder="PC Parts Perú"
                  value={storeName}
                  onChange={(e) => handleStoreNameChange(e.target.value)}
                  className="premium-input with-icon"
                  maxLength={200}
                  required
                />
              </div>
              {fieldError('store_name') && <span className="field-error">{fieldError('store_name')}</span>}
            </div>

            <div className="form-group">
              <label htmlFor="slug">Dirección de tu catálogo</label>
              <div className="input-wrapper">
                <Link2 className="input-icon" size={18} />
                <input
                  id="slug"
                  type="text"
                  placeholder="pc-parts-peru"
                  value={slug}
                  onChange={(e) => handleSlugChange(e.target.value)}
                  className="premium-input with-icon"
                  maxLength={80}
                  required
                />
              </div>
              {fieldError('slug')
                ? <span className="field-error">{fieldError('slug')}</span>
                : <span className="field-hint">{window.location.origin}/{slug || 'tu-tienda'}</span>}
            </div>

            <div className="form-group">
              <label htmlFor="whatsapp">WhatsApp de contacto</label>
              <div className="input-wrapper">
                <Phone className="input-icon" size={18} />
                <input
                  id="whatsapp"
                  type="tel"
                  placeholder="51999888777"
                  value={whatsapp}
                  onChange={(e) => setWhatsapp(e.target.value)}
                  className="premium-input with-icon"
                  maxLength={20}
                  required
                />
              </div>
              {fieldError('whatsapp')
                ? <span className="field-error">{fieldError('whatsapp')}</span>
                : <span className="field-hint">Con código de país, sin espacios ni signos.</span>}
            </div>
          </fieldset>

          <fieldset className="form-section" disabled={isLoading}>
            <legend>Tu cuenta de administrador</legend>

            <div className="form-group">
              <label htmlFor="admin-name">Tu nombre</label>
              <div className="input-wrapper">
                <User className="input-icon" size={18} />
                <input
                  id="admin-name"
                  type="text"
                  placeholder="Juan Pérez"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="premium-input with-icon"
                  maxLength={200}
                  required
                />
              </div>
              {fieldError('name') && <span className="field-error">{fieldError('name')}</span>}
            </div>

            <div className="form-group">
              <label htmlFor="admin-email">Correo electrónico</label>
              <div className="input-wrapper">
                <Mail className="input-icon" size={18} />
                <input
                  id="admin-email"
                  type="email"
                  placeholder="tu@correo.com"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="premium-input with-icon"
                  required
                />
              </div>
              {fieldError('email') && <span className="field-error">{fieldError('email')}</span>}
            </div>

            <div className="form-group">
              <label htmlFor="admin-password">Contraseña</label>
              <div className="input-wrapper">
                <Lock className="input-icon" size={18} />
                <input
                  id="admin-password"
                  type={showPassword ? 'text' : 'password'}
                  placeholder="Mínimo 8 caracteres"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="premium-input with-icon"
                  minLength={8}
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="password-toggle"
                  disabled={isLoading}
                >
                  {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
              {fieldError('password') && <span className="field-error">{fieldError('password')}</span>}
            </div>

            <div className="form-group">
              <label htmlFor="admin-password-confirm">Repite la contraseña</label>
              <div className="input-wrapper">
                <Lock className="input-icon" size={18} />
                <input
                  id="admin-password-confirm"
                  type={showPassword ? 'text' : 'password'}
                  placeholder="••••••••"
                  value={passwordConfirmation}
                  onChange={(e) => setPasswordConfirmation(e.target.value)}
                  className="premium-input with-icon"
                  minLength={8}
                  required
                />
              </div>
            </div>
          </fieldset>

          <button type="submit" disabled={isLoading} className="btn-primary btn-block">
            {isLoading ? (
              <>
                <Loader2 className="spinner" size={18} />
                Creando tu tienda...
              </>
            ) : (
              'Crear mi tienda'
            )}
          </button>

          <p className="register-footer">
            ¿Ya tienes una tienda? <Link to="/login">Inicia sesión</Link>
          </p>
        </form>
      </div>

      <style>{`
        .register-container {
          position: relative;
          display: flex;
          align-items: center;
          justify-content: center;
          min-height: 100vh;
          width: 100vw;
          background-color: var(--bg-app);
          padding: 1.5rem;
        }

        .register-card {
          position: relative;
          z-index: 10;
          width: 100%;
          max-width: 520px;
          padding: 2.5rem;
          display: flex;
          flex-direction: column;
          gap: 2rem;
          border-radius: 20px;
          margin: 2rem 0;
        }

        .register-header {
          display: flex;
          flex-direction: column;
          align-items: center;
          text-align: center;
        }

        .logo-badge {
          display: flex;
          align-items: center;
          justify-content: center;
          width: 56px;
          height: 56px;
          border-radius: 14px;
          background: rgba(37, 99, 235, 0.08);
          border: 1px solid rgba(37, 99, 235, 0.2);
          color: var(--primary);
          margin-bottom: 1rem;
        }

        .register-header h1 {
          font-size: 1.75rem;
          margin-bottom: 0.35rem;
          color: var(--text-primary);
        }

        .register-header p {
          color: var(--text-secondary);
          font-size: 0.9rem;
        }

        .register-form {
          display: flex;
          flex-direction: column;
          gap: 1.75rem;
        }

        .form-section {
          display: flex;
          flex-direction: column;
          gap: 1.25rem;
          border: 1px solid var(--border);
          border-radius: 14px;
          padding: 1.25rem;
          margin: 0;
        }

        .form-section:disabled {
          opacity: 0.7;
        }

        .form-section legend {
          padding: 0 0.5rem;
          font-size: 0.8rem;
          font-weight: 600;
          text-transform: uppercase;
          letter-spacing: 0.04em;
          color: var(--text-secondary);
        }

        .form-group {
          display: flex;
          flex-direction: column;
          gap: 0.5rem;
        }

        .form-group label {
          color: var(--text-primary);
          font-size: 0.85rem;
          font-weight: 500;
        }

        .input-wrapper {
          position: relative;
          display: flex;
          align-items: center;
        }

        .premium-input.with-icon {
          padding-left: 2.75rem;
        }

        .input-icon {
          position: absolute;
          left: 1rem;
          color: var(--text-muted);
          pointer-events: none;
          transition: var(--transition);
        }

        .premium-input:focus ~ .input-icon,
        .premium-input:focus + .input-icon {
          color: var(--primary);
        }

        .password-toggle {
          position: absolute;
          right: 1rem;
          background: transparent;
          border: none;
          color: var(--text-muted);
          cursor: pointer;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 0.25rem;
          transition: var(--transition);
        }

        .password-toggle:hover {
          color: var(--text-primary);
        }

        .field-hint {
          font-size: 0.75rem;
          color: var(--text-muted);
          word-break: break-all;
        }

        .field-error {
          font-size: 0.75rem;
          color: var(--danger, #ef4444);
        }

        .btn-block {
          width: 100%;
          padding: 0.85rem;
        }

        .register-footer {
          text-align: center;
          font-size: 0.85rem;
          color: var(--text-secondary);
        }

        .register-footer a {
          color: var(--primary);
          font-weight: 500;
        }

        .spinner {
          animation: spin 1s linear infinite;
        }

        @keyframes spin {
          from { transform: rotate(0deg); }
          to { transform: rotate(360deg); }
        }

        @media (max-width: 480px) {
          .register-card {
            padding: 1.75rem;
          }
        }
      `}</style>
    </div>
  );
}
