import './LoginPage.css';

import { useState, useEffect } from 'react';
import { useNavigate, Link, useSearchParams } from 'react-router-dom';
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

  const [searchParams, setSearchParams] = useSearchParams();

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

  /**
   * Resultado de la verificación del correo (FUN-5).
   *
   * Aterriza aquí porque el enlace lo abre la API —es ella quien comprueba la
   * firma— y luego redirige al panel con este parámetro. Se avisa con un toast y
   * no con un cartel en la página a propósito: el efecto de arriba puede mandar
   * al dueño directo al dashboard si ya tenía sesión abierta en este navegador,
   * y el toast vive en la raíz de la app, así que sobrevive al salto.
   *
   * El parámetro se borra de la URL después de leerlo para que un refresco no
   * repita el mensaje.
   */
  useEffect(() => {
    const resultado = searchParams.get('verificacion');
    if (!resultado) return;

    if (resultado === 'ok') {
      toast.success('¡Correo confirmado! Tu tienda ya es pública.');
    } else if (resultado === 'caducada') {
      toast.error('Ese enlace ya caducó. Entra al panel y pide uno nuevo.');
    } else {
      toast.error('No pudimos confirmar el correo con ese enlace. Pide uno nuevo desde el panel.');
    }

    searchParams.delete('verificacion');
    setSearchParams(searchParams, { replace: true });
  }, [searchParams, setSearchParams]);

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
    <div className="login-container animate-fade-in page-login">
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
            <div className="label-row">
              <label htmlFor="password">Contraseña</label>
              <Link to="/forgot-password" className="forgot-link">
                ¿La olvidaste?
              </Link>
            </div>
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

    </div>
  );
}
