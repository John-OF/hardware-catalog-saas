import './ResetPasswordPage.css';

import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { toast } from 'react-hot-toast';
import { Lock, Eye, EyeOff, Loader2, ShieldCheck, AlertTriangle } from 'lucide-react';
import { resetPassword } from '../../api/auth';

/** Errores de validación del backend, por campo. */
type FieldErrors = Record<string, string[]>;

type ApiError = {
  response?: { status?: number; data?: { message?: string; errors?: FieldErrors } };
};

export default function ResetPasswordPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  // Ambos llegan en el enlace del correo: el token no identifica al usuario por
  // sí solo, el broker de Laravel necesita también el correo para resolverlo.
  const token = searchParams.get('token') ?? '';
  const email = searchParams.get('email') ?? '';

  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [isLoading, setIsLoading] = useState(false);

  const fieldError = (field: string): string | null => errors[field]?.[0] ?? null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});

    if (password !== passwordConfirmation) {
      setErrors({ password: ['Las contraseñas no coinciden.'] });
      return;
    }

    setIsLoading(true);
    try {
      await resetPassword({
        token,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });

      toast.success('Contraseña actualizada. Entra con la nueva.');
      navigate('/login', { replace: true });
    } catch (error) {
      const response = (error as ApiError).response;
      const validationErrors = response?.data?.errors;

      if (validationErrors) {
        setErrors(validationErrors);
      } else if (response?.status === 429) {
        toast.error('Demasiados intentos. Espera un minuto y vuelve a probar.');
      } else {
        console.error(error);
        toast.error(response?.data?.message || 'No pudimos cambiar la contraseña. Inténtalo de nuevo.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  // Enlace manipulado o pegado a medias: no tiene sentido mostrar el formulario.
  if (!token || !email) {
    return (
      <div className="auth-container animate-fade-in page-reset-password">
        <div className="auth-card glass-card animate-scale-in">
          <div className="auth-header">
            <div className="logo-badge warning">
              <AlertTriangle size={32} />
            </div>
            <h1>Enlace incompleto</h1>
            <p>
              Abre el enlace tal como llegó en el correo. Si lo copiaste a mano, es probable que se haya
              perdido una parte.
            </p>
          </div>
          <Link to="/forgot-password" className="btn-primary btn-block btn-link">
            Pedir un enlace nuevo
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="auth-container animate-fade-in page-reset-password">
      <div className="auth-card glass-card animate-scale-in">
        <div className="auth-header">
          <div className="logo-badge">
            <ShieldCheck size={32} />
          </div>
          <h1>Elige una contraseña nueva</h1>
          <p>
            Para <strong>{email}</strong>. Al guardarla se cerrarán las sesiones abiertas de esta cuenta.
          </p>
        </div>

        <form onSubmit={handleSubmit} className="auth-form" noValidate>
          <div className="form-group">
            <label htmlFor="password">Nueva contraseña</label>
            <div className="input-wrapper">
              <Lock className="input-icon" size={18} />
              <input
                id="password"
                type={showPassword ? 'text' : 'password'}
                placeholder="Mínimo 8 caracteres"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                disabled={isLoading}
                className="premium-input with-icon"
                autoFocus
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
            <label htmlFor="password-confirmation">Repite la contraseña</label>
            <div className="input-wrapper">
              <Lock className="input-icon" size={18} />
              <input
                id="password-confirmation"
                type={showPassword ? 'text' : 'password'}
                placeholder="••••••••"
                value={passwordConfirmation}
                onChange={(e) => setPasswordConfirmation(e.target.value)}
                disabled={isLoading}
                className="premium-input with-icon"
                required
              />
            </div>
          </div>

          {/* El backend devuelve aquí el "enlace inválido o caducado". */}
          {fieldError('email') && (
            <div className="alert-error">
              {fieldError('email')}{' '}
              <Link to="/forgot-password">Pedir uno nuevo</Link>
            </div>
          )}

          <button type="submit" disabled={isLoading} className="btn-primary btn-block">
            {isLoading ? (
              <>
                <Loader2 className="spinner" size={18} />
                Guardando...
              </>
            ) : (
              'Guardar contraseña'
            )}
          </button>

          <p className="auth-footer">
            <Link to="/login" className="back-link">
              Volver al inicio de sesión
            </Link>
          </p>
        </form>
      </div>

    </div>
  );
}

