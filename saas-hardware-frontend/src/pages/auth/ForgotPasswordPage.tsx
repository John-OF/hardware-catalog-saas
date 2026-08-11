import { useState } from 'react';
import { Link } from 'react-router-dom';
import { toast } from 'react-hot-toast';
import { Mail, Loader2, KeyRound, MailCheck, ArrowLeft } from 'lucide-react';
import { forgotPassword } from '../../api/auth';

/** Forma mínima de un error de axios tal como lo devuelve la API. */
type ApiError = {
  response?: { status?: number; data?: { message?: string } };
};

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [sent, setSent] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email) {
      toast.error('Escribe tu correo electrónico.');
      return;
    }

    setIsLoading(true);
    try {
      await forgotPassword(email);
      // El backend responde lo mismo exista o no el correo, así que la pantalla
      // de confirmación tampoco puede afirmar que se envió: diría de más.
      setSent(true);
    } catch (error) {
      console.error(error);
      const response = (error as ApiError).response;
      const message =
        response?.status === 429
          ? 'Demasiados intentos. Espera un minuto y vuelve a probar.'
          : response?.data?.message || 'No pudimos procesar la solicitud. Inténtalo de nuevo.';
      toast.error(message);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="auth-container animate-fade-in">
      <div className="auth-card glass-card animate-scale-in">
        <div className="auth-header">
          <div className="logo-badge">{sent ? <MailCheck size={32} /> : <KeyRound size={32} />}</div>
          <h1>{sent ? 'Revisa tu correo' : '¿Olvidaste tu contraseña?'}</h1>
          <p>
            {sent
              ? 'Si ese correo pertenece a una tienda registrada, te enviamos un enlace para elegir una contraseña nueva. Caduca en 60 minutos.'
              : 'Escribe el correo con el que administras tu tienda y te enviamos un enlace para restablecerla.'}
          </p>
        </div>

        {sent ? (
          <div className="auth-form">
            <p className="auth-hint">
              ¿No te llegó? Revisa la carpeta de spam o{' '}
              <button type="button" className="link-button" onClick={() => setSent(false)}>
                prueba con otro correo
              </button>
              .
            </p>
            <Link to="/login" className="btn-primary btn-block btn-link">
              Volver al inicio de sesión
            </Link>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="auth-form">
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
                  autoFocus
                  required
                />
              </div>
            </div>

            <button type="submit" disabled={isLoading} className="btn-primary btn-block">
              {isLoading ? (
                <>
                  <Loader2 className="spinner" size={18} />
                  Enviando...
                </>
              ) : (
                'Enviarme el enlace'
              )}
            </button>

            <p className="auth-footer">
              <Link to="/login" className="back-link">
                <ArrowLeft size={14} /> Volver al inicio de sesión
              </Link>
            </p>
          </form>
        )}
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
          overflow: hidden;
          padding: 1.5rem;
        }

        .auth-card {
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
          font-size: 1.6rem;
          margin-bottom: 0.35rem;
          color: var(--text-primary);
        }

        .auth-header p {
          color: var(--text-secondary);
          font-size: 0.9rem;
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
          transition: var(--transition);
        }

        .premium-input:focus + .input-icon {
          color: var(--primary);
        }

        .btn-block {
          width: 100%;
          padding: 0.85rem;
        }

        .btn-link {
          text-decoration: none;
          display: flex;
          align-items: center;
          justify-content: center;
        }

        .auth-hint {
          font-size: 0.85rem;
          color: var(--text-secondary);
          text-align: center;
          line-height: 1.6;
        }

        .link-button {
          background: none;
          border: none;
          padding: 0;
          font: inherit;
          color: var(--primary);
          cursor: pointer;
        }

        .auth-footer {
          text-align: center;
          font-size: 0.85rem;
        }

        .back-link {
          display: inline-flex;
          align-items: center;
          gap: 0.35rem;
          color: var(--text-secondary);
          font-weight: 500;
        }

        .back-link:hover {
          color: var(--primary);
        }

        .spinner {
          animation: spin 1s linear infinite;
        }

        @keyframes spin {
          from { transform: rotate(0deg); }
          to { transform: rotate(360deg); }
        }

        @media (max-width: 480px) {
          .auth-card {
            padding: 1.75rem;
          }
        }
      `}</style>
    </div>
  );
}
