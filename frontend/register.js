document.getElementById('registerForm').addEventListener('submit', async (e) => {
e.preventDefault();

    const form = e.target;
    const email = form.email.value.trim();
    const password = form.password.value;
    const confirm = form.confirm_password.value;

  // Client-side checks
    if (!email || !password) return alert('Email and password are required.');
    if (password.length < 8)   return alert('Password must be at least 8 characters.');
    if (password !== confirm)  return alert('Passwords do not match.');

const body = new URLSearchParams(new FormData(form));

try {
    const res = await fetch('../backend-php/api/auth/register.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type':'application/x-www-form-urlencoded' },
        body
    });
    const data = await res.json();
    if (data.ok) {
        alert('Registration successful. Please log in.');
        window.location.href = 'login.html';
    } else {
    alert(data.error || 'Registration failed.');
    }
} catch (err) {
    console.error(err);
    alert('Network error.');
}
});


