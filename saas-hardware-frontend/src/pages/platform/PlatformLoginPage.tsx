import './PlatformLoginPage.css';

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
    <div className="auth-container animate-fade-in page-platform-login">
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

    </div>
  );
}
