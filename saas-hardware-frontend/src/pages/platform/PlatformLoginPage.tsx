import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'react-hot-toast';
import { Lock, Mail, Eye, EyeOff, Loader2, ShieldCheck } from 'lucide-react';
import { platformLogin } from '../../api/platform';
import { usePlatformAuthStore } from '../../stores/platformAuthStore';

type ApiError = { response?: { status?: number; data?: { message?: string } } };

export default function PlatformLoginPage() {
  const navigate = useNavigate();
  const setPlatformAuth = usePlatformAuthStore((s) => s.setPlatformAuth);
  const isAuthenticated = usePlatformAuthStore((s) => s.isAuthenticated);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    if (isAuthenticated) navigate('/platform', { replace: true });
  }, [isAuthenticated, navigate]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    try {
      const { token, user } = await platformLogin(email, password);
      setPlatformAuth(token, user);
      navigate('/platform', { replace: true });
    } catch (error) {
      const response = (error as ApiError).response;
      toast.error(
        response?.status === 429
          ? 'Demasiados intentos. Espera un minuto.'
          : 'Las credenciales son incorrectas.',
      );
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="auth-container animate-fade-in">
      <div className="auth-card glass-card animate-scale-in">
        <div className="auth-header">
          <div className="logo-badge">
            <ShieldCheck size={32} />
          </div>
          <h1>Administración de plataforma</h1>
          <p>Acceso del operador. Esta no es la entrada del panel de tu tienda.</p>
        </div>

        <form onSubmit={handleSubmit} className="auth-form">
          <div className="form-group">
            <label htmlFor="email">Correo del operador</label>
            <div className="input-wrapper">
              <Mail className="input-icon" size={18} />
              <input
                id="email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                disabled={isLoading}
                className="premium-input with-icon"
                autoFocus
                required
              />
            </div>
          </div>

          <div className="form-group">
            <label htmlFor="password">Contraseña</label>
            <div className="input-wrapper">
              <Lock className="input-icon" size={18} />
              <input
                id="password"
                type={showPassword ? 'text' : 'password'}
                placeholder="••••••••"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                disabled={isLoading}
                className="premium-input with-icon"
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
          </div>

          <button type="submit" disabled={isLoading} className="btn-primary btn-block">
            {isLoading ? (
              <>
                <Loader2 className="spinner" size={18} /> Entrando...
              </>
            ) : (
              'Entrar'
            )}
          </button>
        </form>
      </div>

      <style>{`
        .auth-container {
          position: relative;
          display: flex;
          align-items: center;
          justify-content: center;
          min-height: 100vh;
          width: 100vw;
          background-color: var(--bg-app);
          padding: 1.5rem;
        }

        .auth-card {
          width: 100%;
          max-width: 440px;
          padding: 2.5rem;
          display: flex;
          flex-direction: column;
          gap: 2rem;
          border-radius: 20px;
        }

        .auth-header {
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

        .auth-header h1 {
          font-size: 1.5rem;
          margin-bottom: 0.35rem;
          color: var(--text-primary);
        }

        .auth-header p {
          color: var(--text-secondary);
          font-size: 0.88rem;
          line-height: 1.5;
        }

        .auth-form {
          display: flex;
          flex-direction: column;
          gap: 1.5rem;
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
        }

        .password-toggle {
          position: absolute;
          right: 1rem;
          background: transparent;
          border: none;
          color: var(--text-muted);
          cursor: pointer;
          display: flex;
          padding: 0.25rem;
        }

        .btn-block {
          width: 100%;
          padding: 0.85rem;
        }

        .spinner {
          animation: spin 1s linear infinite;
        }

        @keyframes spin {
          from { transform: rotate(0deg); }
          to { transform: rotate(360deg); }
        }
      `}</style>
    </div>
  );
}
