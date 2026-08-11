import { useState, useEffect } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { toast } from 'react-hot-toast';
import { Lock, Mail, Eye, EyeOff, Loader2, Cpu } from 'lucide-react';
import { loginUser } from '../../api/auth';
import { useAuthStore } from '../../stores/authStore';
import { useTenantStore } from '../../stores/tenantStore';

export default function LoginPage() {
  const navigate = useNavigate();
  const setAuth = useAuthStore((s) => s.setAuth);
  const setTenant = useTenantStore((s) => s.setTenant);
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    // Si ya está autenticado, redirigir directo al dashboard
    if (isAuthenticated) {
      navigate('/dashboard', { replace: true });
    }
  }, [isAuthenticated, navigate]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email || !password) {
      toast.error('Por favor completa todos los campos.');
      return;
    }

    setIsLoading(true);
    try {
      const response = await loginUser({ email, password });
      
      // Guardar en Zustand
      setAuth(response.token, response.user);
      setTenant(response.tenant);

      toast.success(`¡Bienvenido de nuevo, ${response.user.name}!`);
      
      // Redirigir al panel de control principal
      navigate('/dashboard', { replace: true });
    } catch (error: any) {
      console.error(error);
      const message = error.response?.data?.message || 'Las credenciales ingresadas son incorrectas.';
      toast.error(message);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="login-container animate-fade-in">
      <div className="login-card glass-card animate-scale-in">
        <div className="login-header">
          <div className="logo-badge">
            <Cpu size={32} />
          </div>
          <h1>SaaS Hardware</h1>
          <p>Panel de Administración</p>
        </div>

        <form onSubmit={handleSubmit} className="login-form">
          <div className="form-group">
            <label htmlFor="email">Correo Electrónico</label>
            <div className="input-wrapper">
              <Mail className="input-icon" size={18} />
              <input
                id="email"
                type="email"
                placeholder="ejemplo@tienda.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                disabled={isLoading}
                className="premium-input with-icon"
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
                <Loader2 className="spinner" size={18} />
                Iniciando sesión...
              </>
            ) : (
              'Ingresar al Panel'
            )}
          </button>

          <p className="login-footer">
            ¿Aún no tienes tienda? <Link to="/register">Créala gratis</Link>
          </p>
        </form>
      </div>

      <style>{`
        .login-container {
          position: relative;
          display: flex;
          align-items: center;
          justify-content: center;
          min-height: 100vh;
          width: 100vw;
          background-color: var(--bg-app);
          overflow: hidden;
          padding: 1.5rem;
        }

        .login-card {
          position: relative;
          z-index: 10;
          width: 100%;
          max-width: 440px;
          padding: 2.5rem;
          display: flex;
          flex-direction: column;
          gap: 2rem;
          border-radius: 20px;
        }

        .login-header {
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
          font-size: 1.5rem;
          margin-bottom: 1rem;
        }

        .login-header h1 {
          font-size: 1.75rem;
          margin-bottom: 0.35rem;
          color: var(--text-primary);
        }

        .login-header p {
          color: var(--text-secondary);
          font-size: 0.9rem;
        }

        .login-form {
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

        .btn-block {
          width: 100%;
          padding: 0.85rem;
        }

        .login-footer {
          text-align: center;
          font-size: 0.85rem;
          color: var(--text-secondary);
        }

        .login-footer a {
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
          .login-card {
            padding: 1.75rem;
          }
        }
      `}</style>
    </div>
  );
}
